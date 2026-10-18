<?php

namespace App\Services;

use App\Models\AiBreakdownLog;
use App\Models\Task;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiTaskBreakdownService
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const MAX_SUBTASKS = 7;

    public function isConfigured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * Break a task down into subtasks.
     *
     * Always returns an array with a `source`:
     *  - 'ai':          ['source' => 'ai', 'subtasks' => [['title', 'priority', 'estimated_minutes'], ...]]
     *  - 'unavailable': ['source' => 'unavailable', 'reason' => 'not_configured'|'unreachable'|'api_error'|'unparseable', 'subtasks' => []]
     *
     * An unavailable result never carries canned subtasks, so callers cannot
     * present placeholder output as AI-generated. Every call that actually
     * reached for the API (success or failure) is written to AiBreakdownLog.
     *
     * @return array{source: string, subtasks: list<array{title: string, priority: string, estimated_minutes: int|null}>, reason?: string}
     */
    public function breakdown(Task $task, int $userId): array
    {
        if (! $this->isConfigured()) {
            return $this->unavailable('not_configured');
        }

        $prompt = $this->buildPrompt($task);

        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.api_key'),
                'anthropic-version' => (string) config('services.anthropic.version', '2023-06-01'),
            ])
                ->acceptJson()
                ->timeout((int) config('services.anthropic.timeout', 30))
                ->post(self::ENDPOINT, [
                    'model' => config('services.anthropic.model'),
                    'max_tokens' => 800,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (ConnectionException $e) {
            Log::error('AiTaskBreakdown API unreachable', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            $this->recordFailure($task, $userId, $prompt, 'unreachable');

            return $this->unavailable('unreachable');
        }

        if (! $response->successful()) {
            Log::error('AiTaskBreakdown API error', [
                'task_id' => $task->id,
                'status' => $response->status(),
                'error_type' => $response->json('error.type'),
            ]);
            $this->recordFailure($task, $userId, $prompt, 'api_error', 'HTTP '.$response->status().' '.$response->json('error.type'));

            return $this->unavailable('api_error');
        }

        $text = $this->responseText($response->json('content', []));
        $subtasks = $this->parseBreakdown($text);

        AiBreakdownLog::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'prompt' => $prompt,
            'response' => $subtasks === null ? '[unparseable] '.$text : $text,
            'tokens_used' => $response->json('usage.output_tokens'),
        ]);

        if ($subtasks === null) {
            Log::warning('AiTaskBreakdown response could not be parsed', ['task_id' => $task->id]);

            return $this->unavailable('unparseable');
        }

        return ['source' => self::SOURCE_AI, 'subtasks' => $subtasks];
    }

    /** @return array{source: string, reason: string, subtasks: array{}} */
    private function unavailable(string $reason): array
    {
        return ['source' => self::SOURCE_UNAVAILABLE, 'reason' => $reason, 'subtasks' => []];
    }

    private function recordFailure(Task $task, int $userId, string $prompt, string $reason, string $detail = ''): void
    {
        AiBreakdownLog::create([
            'task_id' => $task->id,
            'user_id' => $userId,
            'prompt' => $prompt,
            'response' => trim("[unavailable:{$reason}] {$detail}"),
            'tokens_used' => null,
        ]);
    }

    /** @param  array<int, mixed>  $content */
    private function responseText(array $content): string
    {
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }

    private function buildPrompt(Task $task): string
    {
        $desc = $task->description ? "\nDescription: {$task->description}" : '';

        return <<<PROMPT
Break this task down into actionable subtasks.

Task: {$task->title}{$desc}
Priority: {$task->priority}

Return a JSON object:
{
  "subtasks": [
    {"title": "Subtask title", "priority": "medium", "estimated_minutes": 30}
  ]
}

Create 3-7 subtasks. Priority: low/medium/high/urgent. estimated_minutes: realistic time estimate.
Return only the JSON object.
PROMPT;
    }

    /**
     * Model output is untrusted: keep only well-formed subtasks and coerce
     * priority/estimate into the values the tasks table accepts.
     *
     * @return list<array{title: string, priority: string, estimated_minutes: int|null}>|null
     */
    private function parseBreakdown(string $text): ?array
    {
        $json = trim($text);
        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```[a-z]*\n?/', '', $json);
            $json = preg_replace('/\n?```$/', '', $json);
        }

        $data = json_decode(trim($json ?? ''), true);
        if (! is_array($data) || ! isset($data['subtasks']) || ! is_array($data['subtasks'])) {
            return null;
        }

        $subtasks = [];
        foreach ($data['subtasks'] as $sub) {
            $title = is_array($sub) && is_string($sub['title'] ?? null) ? trim($sub['title']) : '';
            if ($title === '') {
                continue;
            }

            $minutes = $sub['estimated_minutes'] ?? null;

            $subtasks[] = [
                'title' => Str::limit($title, 255, ''),
                'priority' => in_array($sub['priority'] ?? null, Task::PRIORITIES, true) ? $sub['priority'] : 'medium',
                'estimated_minutes' => is_int($minutes) && $minutes >= 1 ? $minutes : null,
            ];

            if (count($subtasks) === self::MAX_SUBTASKS) {
                break;
            }
        }

        return $subtasks === [] ? null : $subtasks;
    }
}
