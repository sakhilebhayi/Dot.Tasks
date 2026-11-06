<?php

namespace App\Services;

use App\Models\AiBreakdownLog;
use App\Models\Task;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one way out to the AI model. It stays off until there is both a key and a monthly token cap, and a month past its
 * cap stops. Every call that goes out is logged with what it used. It only ever hands text back: nothing here changes a
 * task, so a person always decides.
 */
class AiClient
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public function isConfigured(): bool
    {
        return filled(config('services.anthropic.api_key')) && (int) config('services.anthropic.monthly_token_cap') > 0;
    }

    public function overCap(): bool
    {
        return AiBreakdownLog::where('created_at', '>=', Carbon::now()->startOfMonth())->sum('tokens_used') >= (int) config('services.anthropic.monthly_token_cap');
    }

    /**
     * @param  array<string, mixed>  $schema  the JSON shape the answer must take
     * @return array{ok: bool, text?: string, reason?: string}
     */
    public function ask(Task $task, int $userId, string $prompt, array $schema): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'reason' => 'not_configured'];
        }

        if ($this->overCap()) {
            return ['ok' => false, 'reason' => 'over_cap'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.api_key'),
                'anthropic-version' => (string) config('services.anthropic.version'),
                'anthropic-beta' => 'server-side-fallback-2026-07-01',
            ])
                ->acceptJson()
                ->timeout((int) config('services.anthropic.timeout', 60))
                ->post(self::ENDPOINT, [
                    'model' => config('services.anthropic.model'),
                    'fallbacks' => 'default',
                    'max_tokens' => 4000,
                    'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            Log::error('Ai API unreachable', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            $this->record($task, $userId, $prompt, '[unavailable:unreachable]');

            return ['ok' => false, 'reason' => 'unreachable'];
        }

        if (! $response->successful()) {
            Log::error('Ai API error', ['task_id' => $task->id, 'status' => $response->status(), 'error_type' => $response->json('error.type')]);
            $this->record($task, $userId, $prompt, trim('[unavailable:api_error] HTTP '.$response->status().' '.$response->json('error.type')));

            return ['ok' => false, 'reason' => 'api_error'];
        }

        $used = (int) $response->json('usage.input_tokens') + (int) $response->json('usage.output_tokens');

        if ($response->json('stop_reason') === 'refusal') {
            $this->record($task, $userId, $prompt, '[unavailable:refusal]', $used);

            return ['ok' => false, 'reason' => 'refused'];
        }

        $text = $this->text($response->json('content', []));
        $this->record($task, $userId, $prompt, $text, $used);

        return ['ok' => true, 'text' => $text];
    }

    public function note(Task $task, int $userId, string $prompt, string $text): void
    {
        AiBreakdownLog::where('task_id', $task->id)->where('user_id', $userId)->latest('id')->first()?->update(['response' => $text]);
    }

    private function record(Task $task, int $userId, string $prompt, string $response, ?int $used = null): void
    {
        AiBreakdownLog::create(['task_id' => $task->id, 'user_id' => $userId, 'prompt' => $prompt, 'response' => $response, 'tokens_used' => $used]);
    }

    /** @param  array<int, mixed>  $content */
    private function text(array $content): string
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }
}
