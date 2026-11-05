<?php

namespace Tests\Feature;

use App\Livewire\NotificationSettings;
use App\Livewire\Tasks\MyDay;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskDetail;
use App\Livewire\Teams\UpdateTeamClock;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\NewCommentNotification;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskEventNotification;
use App\Services\InboxLists;
use App\Services\NotificationPreferences;
use App\Services\RemovedTaskPurger;
use App\Services\TaskStatusService;
use App\Support\Today;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §9: discussion, files, followers and notifications that stay connected to the work. */
class TaskCollaborationTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Today::use(null);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function panel(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskDetail::class)->call('openTask', $f->task->id);
    }

    private function board(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskBoard::class, ['taskList' => $f->list]);
    }

    public function test_a_reply_sits_under_the_comment_it_answers(): void
    {
        $f = $this->teamFixture();
        $panel = $this->panel($f)->set('commentBody', 'First thought')->call('addComment');
        $first = TaskComment::sole();

        $panel->call('replyTo', $first->id)->set('commentBody', 'An answer')->call('addComment')->assertSet('replyingTo', null);

        $reply = TaskComment::where('body', 'An answer')->sole();
        $this->assertSame($first->id, $reply->parent_id);
    }

    public function test_a_reply_to_a_reply_stays_on_the_same_thread(): void
    {
        $f = $this->teamFixture();
        $panel = $this->panel($f)->set('commentBody', 'First')->call('addComment');
        $first = TaskComment::sole();
        $panel->call('replyTo', $first->id)->set('commentBody', 'Second')->call('addComment');
        $second = TaskComment::where('body', 'Second')->sole();

        $panel->call('replyTo', $second->id)->set('commentBody', 'Third')->call('addComment');

        $this->assertSame($first->id, TaskComment::where('body', 'Third')->sole()->parent_id);
    }

    public function test_replying_to_a_comment_on_another_task_is_refused(): void
    {
        $f = $this->teamFixture();
        $other = $f->list->tasks()->create(['title' => 'Other', 'status' => 'todo']);
        $theirs = TaskComment::create(['task_id' => $other->id, 'user_id' => $f->owner->id, 'body' => 'Theirs']);

        $this->panel($f)->call('replyTo', $theirs->id)->assertNotFound();
    }

    public function test_an_internal_note_is_hidden_from_a_viewer_and_a_reply_to_one_stays_internal(): void
    {
        $f = $this->teamFixture();
        $panel = $this->panel($f)->set('commentBody', 'Between us')->set('commentInternal', true)->call('addComment');
        $note = TaskComment::sole();
        $panel->call('replyTo', $note->id)->set('commentBody', 'Agreed')->call('addComment');

        $this->assertTrue(TaskComment::where('body', 'Agreed')->sole()->internal);
        $this->panel($f, 'admin')->assertSee('Between us');
        $this->panel($f, 'viewer')->assertDontSee('Between us')->assertDontSee('Agreed');
    }

    public function test_an_internal_note_does_not_reach_a_viewer_even_by_mention(): void
    {
        $f = $this->teamFixture();
        Notification::fake();

        $this->panel($f)->set('commentBody', '@'.$f->viewer->name.' look')->set('commentInternal', true)->call('addComment');

        Notification::assertNotSentTo($f->viewer, MentionedInCommentNotification::class);
    }

    public function test_people_delete_their_own_comments_and_admins_any(): void
    {
        $f = $this->teamFixture();
        $own = TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->editor->id, 'body' => 'Mine']);
        $reply = TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->owner->id, 'body' => 'Reply', 'parent_id' => $own->id]);
        $other = TaskComment::create(['task_id' => $f->task->id, 'user_id' => $f->owner->id, 'body' => 'Owner']);

        $this->panel($f)->call('deleteComment', $other->id)->assertForbidden();
        $this->panel($f)->call('deleteComment', $own->id);
        $this->assertNull($own->fresh());
        $this->assertNull($reply->fresh());

        $this->panel($f, 'admin')->call('deleteComment', $other->id);
        $this->assertNull($other->fresh());
    }

    public function test_a_file_is_attached_and_comes_back_only_to_the_team(): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();

        $this->panel($f)->set('upload', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'))->assertHasNoErrors();

        $file = TaskAttachment::sole();
        $this->assertSame('plan.pdf', $file->name);
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringNotContainsString('plan', $file->path);

        $response = $this->actingAs($f->viewer)->get(route('tasks.files', [$f->task, $file]))->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $this->actingAs($f->outsider)->get(route('tasks.files', [$f->task, $file]))->assertForbidden();
    }

    public function test_a_file_cannot_be_reached_through_another_task(): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();
        $this->panel($f)->set('upload', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'));
        $other = $f->list->tasks()->create(['title' => 'Other', 'status' => 'todo']);

        $this->actingAs($f->editor)->get(route('tasks.files', [$other, TaskAttachment::sole()]))->assertNotFound();
    }

    /** @return array<string, array{string, int}> */
    public static function badFiles(): array
    {
        return ['a program' => ['run.exe', 10], 'a web page' => ['page.html', 10], 'too large' => ['big.pdf', 26 * 1024]];
    }

    #[DataProvider('badFiles')]
    public function test_a_file_of_the_wrong_kind_or_size_is_refused(string $name, int $kilobytes): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();

        $this->panel($f)->set('upload', UploadedFile::fake()->create($name, $kilobytes))->assertHasErrors('upload');

        $this->assertSame(0, TaskAttachment::count());
    }

    public function test_a_viewer_cannot_attach_or_remove_a_file(): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();
        $this->panel($f)->set('upload', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'));
        $file = TaskAttachment::sole();

        $this->panel($f, 'viewer')->call('removeFile', $file->id)->assertForbidden();
        $this->panel($f, 'viewer')->set('upload', UploadedFile::fake()->create('more.pdf', 10, 'application/pdf'))->assertForbidden();

        $this->assertSame(1, TaskAttachment::count());
    }

    public function test_a_removed_file_is_gone_from_the_disk_too(): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();
        $this->panel($f)->set('upload', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'));
        $file = TaskAttachment::sole();

        $this->panel($f)->call('removeFile', $file->id);

        Storage::disk('local')->assertMissing($file->path);
        $this->assertSame(0, TaskAttachment::count());
    }

    public function test_purging_a_removed_task_takes_its_files_and_a_dry_run_does_not(): void
    {
        Storage::fake('local');
        $f = $this->teamFixture();
        $this->panel($f)->set('upload', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'));
        $path = TaskAttachment::sole()->path;
        $f->task->delete();

        app(RemovedTaskPurger::class)->purge(now()->addDay(), true);
        Storage::disk('local')->assertExists($path);

        app(RemovedTaskPurger::class)->purge(now()->addDay());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_following_a_task_brings_its_news(): void
    {
        $f = $this->teamFixture();
        Notification::fake();
        $f->viewer->update(['notification_settings' => ['status' => ['app' => true]]]);

        $this->panel($f, 'viewer')->call('toggleWatching');
        $this->assertSame(1, $f->task->watchers()->count());

        $this->board($f)->call('place', $f->task->id, 'review');
        Notification::assertSentTo($f->viewer, TaskEventNotification::class, fn ($n) => $n->event === 'status' && str_contains($n->message, 'moved to Review'));

        $this->panel($f, 'viewer')->call('toggleWatching');
        $this->assertSame(0, $f->task->watchers()->count());
    }

    public function test_a_new_comment_reaches_people_following(): void
    {
        $f = $this->teamFixture();
        Notification::fake();
        $f->task->watchers()->attach($f->admin);

        $this->panel($f)->set('commentBody', 'News')->call('addComment');

        Notification::assertSentTo($f->admin, NewCommentNotification::class);
    }

    public function test_the_person_who_did_it_hears_nothing(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'creator_id' => $f->editor->id]);
        $f->editor->update(['notification_settings' => ['completed' => ['app' => true]]]);
        Notification::fake();

        $this->board($f)->call('place', $f->task->id, 'done');

        Notification::assertNotSentTo($f->editor, TaskEventNotification::class);
    }

    public function test_the_creator_hears_when_a_task_is_finished_only_if_they_asked(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['creator_id' => $f->owner->id]);
        Notification::fake();

        $this->board($f)->call('place', $f->task->id, 'done');

        Notification::assertNotSentTo($f->owner, TaskEventNotification::class);

        $f->owner->update(['notification_settings' => ['completed' => ['app' => true]]]);
        $this->board($f)->call('place', $f->task->id, 'todo');
        $this->board($f)->call('place', $f->task->id, 'done');

        Notification::assertSentTo($f->owner, TaskEventNotification::class, fn ($n) => $n->event === 'completed');
    }

    public function test_a_due_date_change_reaches_the_person_on_the_task(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->admin->id]);
        $f->admin->update(['notification_settings' => ['due_changed' => ['app' => true]]]);
        Notification::fake();

        $this->panel($f)->call('saveField', 'due_date', '2026-12-24');

        Notification::assertSentTo($f->admin, TaskEventNotification::class, fn ($n, $channels) => $n->event === 'due_changed' && $channels === ['database']);
    }

    public function test_the_person_waiting_hears_when_nothing_holds_it_up(): void
    {
        $f = $this->teamFixture();
        $waiting = $f->list->tasks()->create(['title' => 'Waiting', 'status' => 'todo', 'assignee_id' => $f->admin->id]);
        $waiting->blockedBy()->attach($f->task);
        $second = $f->list->tasks()->create(['title' => 'Also needed', 'status' => 'todo']);
        $waiting->blockedBy()->attach($second);
        Notification::fake();

        $this->board($f)->call('place', $f->task->id, 'done');
        Notification::assertNotSentTo($f->admin, TaskEventNotification::class, fn ($n) => $n->event === 'unblocked');

        $this->board($f)->call('place', $second->id, 'done');
        Notification::assertSentTo($f->admin, TaskEventNotification::class, fn ($n) => $n->event === 'unblocked' && $n->task->is($waiting));
    }

    public function test_a_repeating_task_says_when_it_comes_round(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->admin->id, 'recurrence_type' => 'daily', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date', 'due_date' => '2026-12-01']);
        $f->admin->update(['notification_settings' => ['recurring' => ['app' => true]]]);
        Notification::fake();

        $this->board($f)->call('place', $f->task->id, 'done');

        Notification::assertSentTo($f->admin, TaskEventNotification::class, fn ($n) => $n->event === 'recurring' && $n->task->id !== $f->task->id);
    }

    public function test_an_inbox_stays_quiet(): void
    {
        $f = $this->teamFixture();
        $inbox = app(InboxLists::class)->for($f->editor, $f->team);
        $task = $inbox->tasks()->create(['title' => 'Mine', 'status' => 'todo', 'creator_id' => $f->owner->id]);
        $f->owner->update(['notification_settings' => ['completed' => ['app' => true]]]);
        Notification::fake();

        $this->actingAs($f->editor);
        app(TaskStatusService::class)->move($task, 'done');

        Notification::assertNothingSent();
    }

    public function test_someone_who_left_the_team_hears_nothing(): void
    {
        $f = $this->teamFixture();
        $f->task->watchers()->attach($f->outsider);
        $f->outsider->update(['notification_settings' => ['status' => ['app' => true]]]);
        Notification::fake();

        $this->board($f)->call('place', $f->task->id, 'review');

        Notification::assertNotSentTo($f->outsider, TaskEventNotification::class);
    }

    public function test_a_person_turns_off_what_they_do_not_want(): void
    {
        $f = $this->teamFixture();
        Notification::fake();

        Livewire::actingAs($f->admin)->test(NotificationSettings::class)->call('toggle', 'assigned', 'app');
        $this->panel($f)->call('assignTask', $f->admin->id);

        Notification::assertNotSentTo($f->admin, TaskAssignedNotification::class);
    }

    public function test_preferences_are_each_persons_own_and_the_page_opens(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)->test(NotificationSettings::class)->call('toggle', 'mentioned', 'email');

        $prefs = app(NotificationPreferences::class);
        $this->assertFalse($prefs->wants($f->admin->fresh(), 'mentioned', 'email'));
        $this->assertTrue($prefs->wants($f->editor->fresh(), 'mentioned', 'email'));
        $this->actingAs($f->admin)->get(route('notifications.settings'))->assertOk()->assertSee('Someone mentions me');
    }

    public function test_an_event_or_channel_that_does_not_exist_is_refused(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)->test(NotificationSettings::class)->call('toggle', 'nonsense', 'app')->assertStatus(422);
        Livewire::actingAs($f->admin)->test(NotificationSettings::class)->call('toggle', 'status', 'email')->assertStatus(422);
    }

    public function test_a_task_is_handed_over_with_a_note(): void
    {
        $f = $this->teamFixture();
        Notification::fake();

        $this->panel($f)->call('handOver', $f->admin->id, 'Supplier is waiting on the PO');

        $this->assertSame($f->admin->id, $f->task->fresh()->assignee_id);
        $this->assertSame('Handover to Admin Person Fixture: Supplier is waiting on the PO', TaskComment::sole()->body);
    }

    public function test_a_handover_needs_a_note_and_someone_on_the_team(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)->call('handOver', $f->admin->id, '   ')->assertHasErrors('handover');
        $this->panel($f)->call('handOver', $f->outsider->id, 'Note')->assertForbidden();
        $this->panel($f, 'viewer')->call('handOver', $f->admin->id, 'Note')->assertForbidden();

        $this->assertNull($f->task->fresh()->assignee_id);
    }

    public function test_a_team_sets_its_time_zone(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->owner)->test(UpdateTeamClock::class, ['team' => $f->team])->set('timezone', 'Africa/Johannesburg')->call('save')->assertHasNoErrors();
        $this->assertSame('Africa/Johannesburg', $f->team->fresh()->timezone);

        Livewire::actingAs($f->owner)->test(UpdateTeamClock::class, ['team' => $f->team])->set('timezone', 'Mars/Olympus')->call('save')->assertHasErrors('timezone');
    }

    public function test_a_viewer_cannot_change_the_time_zone(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->viewer)->test(UpdateTeamClock::class, ['team' => $f->team])->set('timezone', 'Asia/Tokyo')->call('save')->assertForbidden();
    }

    public function test_today_follows_the_teams_clock(): void
    {
        Carbon::setTestNow('2026-11-05 23:30:00'); // UTC.
        $f = $this->teamFixture();
        $f->team->forceFill(['timezone' => 'Africa/Johannesburg'])->save();
        $f->task->update(['due_date' => '2026-11-05', 'assignee_id' => $f->editor->id]);

        $this->actingAs($f->editor->fresh())->get(route('tasks.today'))->assertOk()->assertSee('Friday, 6 November');

        $this->assertTrue($f->task->fresh()->isOverdue(), 'it is already tomorrow there');
    }

    public function test_due_soon_reminders_go_by_each_teams_clock(): void
    {
        Carbon::setTestNow('2026-11-05 23:30:00');
        $f = $this->teamFixture();
        $f->team->forceFill(['timezone' => 'Africa/Johannesburg'])->save();
        $f->task->update(['due_date' => '2026-11-05', 'assignee_id' => $f->admin->id]);
        $next = $f->list->tasks()->create(['title' => 'Next', 'status' => 'todo', 'assignee_id' => $f->admin->id, 'due_date' => '2026-11-08']);
        Notification::fake();

        $this->artisan('tasks:check-due-soon')->assertSuccessful();

        Notification::assertSentTo($f->admin, TaskDueSoonNotification::class, fn ($n) => $n->task->is($next));
        Notification::assertNotSentTo($f->admin, TaskDueSoonNotification::class, fn ($n) => $n->task->is($f->task));
    }

    public function test_my_day_counts_from_the_teams_today(): void
    {
        Carbon::setTestNow('2026-11-05 23:30:00');
        $f = $this->teamFixture();
        $f->team->forceFill(['timezone' => 'Africa/Johannesburg'])->save();
        $f->task->update(['due_date' => '2026-11-05', 'assignee_id' => $f->editor->id]);
        Today::use('Africa/Johannesburg');

        $day = Livewire::actingAs($f->editor)->test(MyDay::class)->instance();

        $this->assertSame(['Fixture task'], $day->overdue->pluck('title')->all());
    }
}
