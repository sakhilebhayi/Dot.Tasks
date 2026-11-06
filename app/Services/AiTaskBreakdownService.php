<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiTaskBreakdownService
{
    public const SOURCE_AI = 'ai';

    public const SOURCE_UNAVAILABLE = 'unavailable';

    private const MAX_SUBTASKS = 7;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'subtasks' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']],
                    'estimated_minutes' => ['type' => 'integer'],
                ],
                'required' => ['title', 'priority', 'estimated_minutes'],
                'additionalProperties' => false,
            ]],
        ],
        'required' => ['subtasks'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly AiClient $client) {}

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Suggest subtasks for a task. It writes nothing: a person looks at them and chooses.
     *
     * Always returns an array with a `source`:
     *  - 'ai':          ['source' => 'ai', 'subtasks' => [['title', 'priority', 'estimated_minutes'], ...]]
     *  - 'unavailable': ['source' => 'unavailable', 'reason' => 'not_configured'|'over_cap'|'unreachable'|'api_error'|'refused'|'unparseable', 'subtasks' => []]
     *
     * An unavailable result never carries canned subtasks, so callers cannot
     * present placeholder output as AI-generated.
     *
     * @return array{source: string, subtasks: list<array{title: string, priority: string, estimated_minutes: int|null}>, reason?: string}
     */
    public function breakdown(Task $task, int $userId): array
    {
        $prompt = $this->buildPrompt($task);
        $answer = $this->client->ask($task, $userId, $prompt, self::SCHEMA);

        if (! $answer['ok']) {
            return $this->unavailable($answer['reason']);
        }

        $subtasks = $this->parseBreakdown($answer['text']);

        if ($subtasks === null) {
            Log::warning('AiTaskBreakdown response could not be parsed', ['task_id' => $task->id]);
            $this->client->note($task, $userId, $prompt, '[unparseable] '.$answer['text']);

            return $this->unavailable('unparseable');
        }

        return ['source' => self::SOURCE_AI, 'subtasks' => $subtasks];
    }

    /** @return array{source: string, reason: string, subtasks: array{}} */
    private function unavailable(string $reason): array
    {
        return ['source' => self::SOURCE_UNAVAILABLE, 'reason' => $reason, 'subtasks' => []];
    }

    private function buildPrompt(Task $task): string
    {
        $desc = $task->description ? "\nDescription: {$task->description}" : '';

        return <<<PROMPT
Break this task down into actionable subtasks.

Task: {$task->title}{$desc}
Priority: {$task->priority}

Create 3 to 7 subtasks. estimated_minutes is a realistic time estimate.
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
