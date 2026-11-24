<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskDetail;
use App\Models\AiBreakdownLog;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use App\Services\AiTaskBreakdownService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-61: InfoDot can switch one feature of Dot.Tasks (the AI task breakdown) off without switching Dot.Tasks off. The block is in the service that does the
 * work, so a Livewire call is stopped as well as the screen, before any AI call is made, and nothing the company already has is touched.
 */
class FeatureSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const ACME = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot.redeem_url' => 'https://infodot.test/api/ecosystem/redeem',
            'ecosystem.infodot.platform' => 'tasks',
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
            'ecosystem.entitlement.check' => true,
            'ecosystem.entitlement.url' => null,
            'ecosystem.infodot.companies_url' => 'https://infodot.test/switcher-read',
            'services.anthropic.api_key' => 'test-key-not-real',
            'services.anthropic.monthly_token_cap' => 100000,
            'services.anthropic.model' => 'claude-test-model',
        ]);
        config()->set('ecosystem.infodot.'.'secret', 'secret-for-tests');
    }

    /** A task on a company board linked to InfoDot. @return array{0: User, 1: Task} */
    private function task(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'name' => 'Acme Ltd', 'personal_team' => false]);
        $team->forceFill(['infodot_team_uuid' => self::ACME])->save();
        $owner->switchTeam($team);
        $list = TaskList::create(['team_id' => $team->id, 'owner_id' => $owner->id, 'name' => 'Board']);

        return [$owner->fresh(), $list->tasks()->create(['title' => 'Launch the feature', 'status' => 'todo'])];
    }

    private function infodotSays(array $features, bool $available = true): void
    {
        Http::swap(new Factory);
        Http::fake([
            'https://infodot.test/switcher-read/*' => Http::response('', 503),
            'https://infodot.test/api/ecosystem/companies/*' => Http::response([
                'platform' => 'tasks', 'company' => ['uuid' => self::ACME, 'personal' => false],
                'platforms' => [['key' => 'tasks', 'name' => 'Dot.Tasks', 'status' => 'included', 'entitled' => true, 'is_core' => true, 'available' => $available, 'state' => 'operational', 'message' => null, 'features' => $features]],
            ]),
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_1', 'type' => 'message',
                'content' => [['type' => 'text', 'text' => '{"subtasks":[{"title":"Plan","priority":"high","estimated_minutes":15}]}']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 42],
            ]),
        ]);
    }

    private function anthropicCalls(): int
    {
        return Http::recorded(fn ($request) => $request instanceof Request && str_contains($request->url(), 'api.anthropic.com'))->count();
    }

    public function test_a_switched_off_feature_is_blocked_before_any_ai_call(): void
    {
        [$owner, $task] = $this->task();
        $this->infodotSays(['ai_breakdown' => ['state' => 'disabled', 'message' => 'AI task breakdown is temporarily unavailable. Your data is safe.']]);

        $result = app(AiTaskBreakdownService::class)->breakdown($task, $owner->id);

        $this->assertSame('unavailable', $result['source']);
        $this->assertSame('feature_off', $result['reason']);
        $this->assertSame([], $result['subtasks']);
        $this->assertSame(0, $this->anthropicCalls(), 'no AI call, so no cost');
        $this->assertSame(0, AiBreakdownLog::count());
    }

    public function test_the_livewire_action_says_so_plainly_and_creates_nothing(): void
    {
        [$owner, $task] = $this->task();
        $this->infodotSays(['ai_breakdown' => ['state' => 'disabled', 'message' => 'AI task breakdown is temporarily unavailable. Your data is safe.']]);

        Livewire::actingAs($owner)->test(TaskDetail::class)
            ->call('openTask', $task->id)
            ->call('aiBreakdown')
            ->assertSet('suggested', [])
            ->assertSet('aiError', 'AI task breakdown is temporarily unavailable. Your data is safe.');

        $this->assertSame(0, $task->subtasks()->count());
    }

    public function test_an_available_or_unlisted_feature_works_as_before(): void
    {
        [$owner, $task] = $this->task();

        foreach ([[], ['ai_breakdown' => ['state' => 'available', 'message' => null]], ['something_else' => ['state' => 'disabled', 'message' => 'x']]] as $features) {
            $this->infodotSays($features);
            Cache::flush();
            $this->assertSame('ai', app(AiTaskBreakdownService::class)->breakdown($task, $owner->id)['source']);
        }
    }

    public function test_every_off_state_blocks_and_malformed_rows_do_not(): void
    {
        [$owner, $task] = $this->task();

        foreach (['disabled', 'development', 'retired'] as $state) {
            Cache::flush();
            $this->infodotSays(['ai_breakdown' => ['state' => $state, 'message' => null]]);
            $this->assertSame('feature_off', app(AiTaskBreakdownService::class)->breakdown($task, $owner->id)['reason'], $state);
        }

        Cache::flush();
        $this->infodotSays(['ai_breakdown' => 'nonsense']);
        $this->assertSame('ai', app(AiTaskBreakdownService::class)->breakdown($task, $owner->id)['source']);
    }

    public function test_a_board_with_no_infodot_company_is_never_blocked(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $list = TaskList::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Mine']);
        $task = $list->tasks()->create(['title' => 'Plan', 'status' => 'todo']);
        $this->infodotSays(['ai_breakdown' => ['state' => 'disabled', 'message' => null]]);

        $this->assertSame('ai', app(AiTaskBreakdownService::class)->breakdown($task, $owner->id)['source']);
    }

    public function test_an_infodot_outage_keeps_the_last_switch(): void
    {
        [$owner, $task] = $this->task();
        $this->infodotSays(['ai_breakdown' => ['state' => 'disabled', 'message' => null]]);
        $this->assertSame('feature_off', app(AiTaskBreakdownService::class)->breakdown($task, $owner->id)['reason']);

        Http::swap(new Factory);
        Http::fake(['https://infodot.test/api/ecosystem/companies/*' => Http::response('', 503)]);
        $this->travel(2)->minutes();

        $this->assertSame('feature_off', app(AiTaskBreakdownService::class)->breakdown($task, $owner->id)['reason']);
    }
}
