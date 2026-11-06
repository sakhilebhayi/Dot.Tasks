<?php

namespace Tests\Feature;

use App\Livewire\AuditTrail;
use App\Livewire\Tasks\BoardColumns;
use App\Models\AuditEvent;
use App\Models\TaskAttachment;
use App\Models\TaskRule;
use App\Models\TaskTemplate;
use App\Services\AuditLog;
use App\Services\InboxLists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §17: audit history and recovery. */
class AuditAndRestoreTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function said(object $f): array
    {
        return AuditEvent::where('team_id', $f->team->id)->orderBy('id')->pluck('what')->all();
    }

    public function test_setup_changes_are_recorded_with_who_made_them(): void
    {
        $f = $this->teamFixture();
        $this->actingAs($f->admin);
        AuditEvent::query()->delete();

        $rule = TaskRule::create(['team_id' => $f->team->id, 'created_by' => $f->admin->id, 'name' => 'Chase', 'trigger' => 'task.created', 'conditions' => [], 'actions' => []]);
        $rule->update(['enabled' => false]);
        $rule->delete();

        $this->assertSame(['Added the rule "Chase"', 'Changed the rule "Chase" (enabled)', 'Removed the rule "Chase"'], $this->said($f));
        $this->assertSame([$f->admin->id], AuditEvent::pluck('user_id')->unique()->values()->all());
    }

    public function test_moving_things_around_is_not_an_event(): void
    {
        $f = $this->teamFixture();
        $this->actingAs($f->admin);
        AuditEvent::query()->delete();

        $f->list->columns()->first()->update(['sort_order' => 9]);

        $this->assertSame([], $this->said($f));
    }

    public function test_a_new_board_is_one_event_and_archiving_says_so(): void
    {
        $f = $this->teamFixture();
        $this->actingAs($f->admin);
        AuditEvent::query()->delete();

        $board = $f->team->taskLists()->create(['owner_id' => $f->admin->id, 'name' => 'Road']);
        $board->archive();
        $board->unarchive();

        $this->assertSame(['Added the board "Road"', 'Archived the board "Road"', 'Restored the board "Road"'], $this->said($f));
    }

    public function test_a_private_inbox_stays_out_of_the_log(): void
    {
        $f = $this->teamFixture();
        AuditEvent::query()->delete();

        app(InboxLists::class)->for($f->editor, $f->team);

        $this->assertSame([], $this->said($f));
    }

    public function test_columns_fields_and_templates_are_recorded_from_their_screens(): void
    {
        $f = $this->teamFixture();
        $this->actingAs($f->admin);
        AuditEvent::query()->delete();

        Livewire::test(BoardColumns::class, ['taskList' => $f->list])->call('rename', $f->list->columns()->first()->id, 'Backlog');
        TaskTemplate::create(['team_id' => $f->team->id, 'created_by' => $f->admin->id, 'name' => 'Visit', 'title' => 'Visit']);

        $this->assertSame(['Changed the column "Backlog" (name)', 'Added the template "Visit"'], $this->said($f));
    }

    public function test_members_coming_and_going_are_recorded(): void
    {
        $f = $this->teamFixture();
        AuditEvent::query()->delete();

        TeamMemberAdded::dispatch($f->team, $f->outsider);
        TeamMemberRemoved::dispatch($f->team, $f->outsider);

        $this->assertSame(['Added Otto Outsider Fixture to the team', 'Removed Otto Outsider Fixture from the team'], $this->said($f));
    }

    public function test_an_export_is_recorded(): void
    {
        $f = $this->teamFixture();
        AuditEvent::query()->delete();

        $this->actingAs($f->admin)->get(route('reports.export'))->assertOk();

        $this->assertStringStartsWith('Exported the task report for', $this->said($f)[0]);
    }

    public function test_only_owners_and_admins_read_it_and_only_their_own_business(): void
    {
        $f = $this->teamFixture();
        AuditLog::record($f->team, 'changed', 'Our secret change');
        AuditLog::record($f->outsider->currentTeam, 'changed', 'Their secret change');

        $this->actingAs($f->admin)->get(route('tasks.audit'))->assertOk()->assertSee('Our secret change')->assertDontSee('Their secret change');
        $this->actingAs($f->editor)->get(route('tasks.audit'))->assertForbidden();
        Livewire::actingAs($f->outsider)->test(AuditTrail::class)->assertDontSee('Our secret change');
    }

    public function test_the_links_only_show_to_those_who_can_open_them(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->admin)->get(route('dashboard'))->assertSee('Audit log')->assertSee('Connections');
        $this->actingAs($f->editor)->get(route('dashboard'))->assertDontSee('Audit log')->assertDontSee('Connections');
    }

    public function test_old_entries_go_after_the_retention_period_and_recent_ones_stay(): void
    {
        $f = $this->teamFixture();
        AuditEvent::query()->delete();
        AuditLog::record($f->team, 'changed', 'Recent');
        AuditLog::record($f->team, 'changed', 'Old');
        AuditEvent::where('what', 'Old')->update(['created_at' => now()->subMonths(25)]);

        $this->artisan('model:prune', ['--model' => [AuditEvent::class]])->assertSuccessful();

        $this->assertSame(['Recent'], $this->said($f));
    }

    public function test_a_complete_copy_with_readable_values_and_files_passes(): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();
        $f->owner->forceFill(['two_factor_secret' => Crypt::encrypt('secret', false)])->save();
        Storage::disk('local')->put('tasks/a.pdf', 'x');
        TaskAttachment::create(['task_id' => $f->task->id, 'path' => 'tasks/a.pdf', 'name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1]);

        $this->artisan('tasks:verify-restore', ['--connection' => config('database.default'), '--against' => config('database.default')])
            ->expectsOutputToContain('migrations applied')
            ->expectsOutputToContain('every count matches')
            ->expectsOutputToContain('1 sampled values decrypt')
            ->expectsOutputToContain('1 sampled attachments are on the disk')
            ->expectsOutputToContain('Restore check passed')
            ->assertSuccessful();
    }

    public function test_values_that_do_not_decrypt_with_this_key_fail(): void
    {
        $f = $this->teamFixture();
        $f->owner->forceFill(['two_factor_secret' => 'written-with-another-key'])->save();

        $this->artisan('tasks:verify-restore', ['--connection' => config('database.default')])->expectsOutputToContain('do NOT decrypt')->assertFailed();
    }

    public function test_a_file_missing_from_the_disk_fails(): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();
        TaskAttachment::create(['task_id' => $f->task->id, 'path' => 'tasks/gone.pdf', 'name' => 'gone.pdf', 'mime' => 'application/pdf', 'size' => 1]);

        $this->artisan('tasks:verify-restore', ['--connection' => config('database.default')])->expectsOutputToContain('not on the disk')->assertFailed();
    }

    public function test_a_copy_missing_a_migration_fails(): void
    {
        DB::table('migrations')->where('migration', '2026_11_08_000001_create_audit_events_table')->delete();

        $this->artisan('tasks:verify-restore', ['--connection' => config('database.default')])->expectsOutputToContain('not applied on the copy')->assertFailed();
    }

    public function test_an_unknown_connection_says_so(): void
    {
        $this->artisan('tasks:verify-restore', ['--connection' => 'restore'])->expectsOutputToContain('Cannot connect')->assertFailed();
    }
}
