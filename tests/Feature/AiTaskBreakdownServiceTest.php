<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskDetail;
use App\Models\AiBreakdownLog;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\AiTaskBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AiTaskBreakdownServiceTest extends TestCase
{
    use RefreshDatabase;

    private function task(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $list = TaskList::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Board']);
        $task = $list->tasks()->create(['title' => 'Launch the feature', 'status' => 'todo']);

        return [$owner, $task];
    }

    private function configureKey(): void
    {
        config([
            'services.anthropic.api_key' => 'test-key-not-real',
            'services.anthropic.model' => 'claude-test-model',
        ]);
    }

    private function anthropicBody(string $text): array
    {
        return [
            'id' => 'msg_1',
            'type' => 'message',
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 42],
        ];
    }

    public function test_sends_the_headers_and_model_the_anthropic_api_requires(): void
    {
        $this->configureKey();
        [$owner, $task] = $this->task();
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicBody(
            '{"subtasks":[{"title":"Plan","priority":"high","estimated_minutes":15}]}'
        ))]);

        app(AiTaskBreakdownService::class)->breakdown($task, $owner->id);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->header('x-api-key') === ['test-key-not-real']
                && $request->header('anthropic-version') === ['2023-06-01']
                && $request->header('Authorization') === []
                && $request['model'] === 'claude-test-model';
        });
    }

    public function test_parses_a_successful_response_and_logs_it(): void
    {
        $this->configureKey();
        [$owner, $task] = $this->task();
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicBody(
            "```json\n{\"subtasks\":[{\"title\":\"Plan\",\"priority\":\"high\",\"estimated_minutes\":15},{\"title\":\"Build\",\"priority\":\"bogus\",\"estimated_minutes\":-3}]}\n```"
        ))]);

        $result = app(AiTaskBreakdownService::class)->breakdown($task, $owner->id);

        $this->assertSame('ai', $result['source']);
        $this->assertCount(2, $result['subtasks']);
        $this->assertSame('high', $result['subtasks'][0]['priority']);
        // Model output is untrusted: an invalid priority/estimate is normalised, not passed to the DB.
        $this->assertSame('medium', $result['subtasks'][1]['priority']);
        $this->assertNull($result['subtasks'][1]['estimated_minutes']);
        $this->assertDatabaseHas('ai_breakdown_logs', ['task_id' => $task->id, 'user_id' => $owner->id, 'tokens_used' => 42]);
    }

    public function test_a_401_is_flagged_unavailable_and_logged_rather_than_hidden(): void
    {
        $this->configureKey();
        [$owner, $task] = $this->task();
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error']], 401)]);

        $result = app(AiTaskBreakdownService::class)->breakdown($task, $owner->id);

        $this->assertSame('unavailable', $result['source']);
        $this->assertSame([], $result['subtasks']);
        $this->assertSame('api_error', $result['reason']);
        $log = AiBreakdownLog::where('task_id', $task->id)->first();
        $this->assertNotNull($log, 'a failed API call must leave a log row');
        $this->assertStringContainsString('401', $log->response);
    }

    public function test_a_timeout_is_flagged_unavailable(): void
    {
        $this->configureKey();
        [$owner, $task] = $this->task();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $result = app(AiTaskBreakdownService::class)->breakdown($task, $owner->id);

        $this->assertSame('unavailable', $result['source']);
        $this->assertSame('unreachable', $result['reason']);
        $this->assertDatabaseCount('ai_breakdown_logs', 1);
    }

    public function test_an_unparseable_response_is_flagged_unavailable(): void
    {
        $this->configureKey();
        [$owner, $task] = $this->task();
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicBody('Sure! Here are some ideas.'))]);

        $result = app(AiTaskBreakdownService::class)->breakdown($task, $owner->id);

        $this->assertSame('unavailable', $result['source']);
        $this->assertSame('unparseable', $result['reason']);
        $this->assertSame([], $result['subtasks']);
    }

    public function test_without_an_api_key_no_request_is_made_and_no_canned_subtasks_are_returned(): void
    {
        config(['services.anthropic.api_key' => '']);
        [$owner, $task] = $this->task();
        Http::fake();

        $result = app(AiTaskBreakdownService::class)->breakdown($task, $owner->id);

        Http::assertNothingSent();
        $this->assertSame('unavailable', $result['source']);
        $this->assertSame('not_configured', $result['reason']);
        $this->assertSame([], $result['subtasks']);
    }

    public function test_the_ui_says_unavailable_and_creates_no_subtasks_without_a_key(): void
    {
        config(['services.anthropic.api_key' => '']);
        [$owner, $task] = $this->task();

        Livewire::actingAs($owner)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('aiBreakdown')
            ->assertSet('aiError', fn ($error) => is_string($error) && str_contains($error, 'AI breakdown unavailable'));

        $this->assertSame(0, Task::where('parent_id', $task->id)->count());
    }

    public function test_the_ui_says_unavailable_when_the_api_rejects_the_call(): void
    {
        $this->configureKey();
        [$owner, $task] = $this->task();
        Http::fake(['api.anthropic.com/*' => Http::response([], 401)]);

        Livewire::actingAs($owner)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('aiBreakdown')
            ->assertSet('aiError', fn ($error) => is_string($error) && str_contains($error, 'AI breakdown unavailable'));

        $this->assertSame(0, Task::where('parent_id', $task->id)->count());
    }

    public function test_the_ui_creates_subtasks_from_a_real_response(): void
    {
        $this->configureKey();
        [$owner, $task] = $this->task();
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropicBody(
            '{"subtasks":[{"title":"Plan","priority":"urgent","estimated_minutes":15},{"title":"Build"}]}'
        ))]);

        Livewire::actingAs($owner)
            ->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('aiBreakdown')
            ->assertSet('aiError', null);

        $this->assertSame(['Plan', 'Build'], $task->subtasks()->pluck('title')->all());
    }
}
