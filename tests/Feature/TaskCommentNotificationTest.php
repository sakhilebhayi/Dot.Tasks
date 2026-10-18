<?php

namespace Tests\Feature;

use App\Livewire\NotificationBell;
use App\Livewire\Tasks\CreateTask;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Notifications\NewCommentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class TaskCommentNotificationTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function comment(object $f, string $as, string $body, ?Task $task = null)
    {
        return Livewire::actingAs($f->{$as})
            ->test(TaskDetail::class)
            ->call('openTask', ($task ?? $f->task)->id)
            ->set('commentBody', $body)
            ->call('addComment')
            ->assertHasNoErrors();
    }

    /** @return array<string, int> notification type => count, for one user */
    private function received(User $user): array
    {
        return $user->notifications()->get()->countBy('type')->all();
    }

    public function test_a_mention_notifies_the_mentioned_team_member(): void
    {
        $f = $this->teamFixture();

        $this->comment($f, 'editor', 'Could you check this, @Viewer Person Fixture?');

        $this->assertSame([MentionedInCommentNotification::class => 1], $this->received($f->viewer));

        $data = $f->viewer->notifications()->first()->data;
        $this->assertSame('mention', $data['type']);
        $this->assertSame(route('task-lists.show', $f->list), $data['url']);
        $this->assertSame($f->task->id, $data['task_id']);
        $this->assertStringContainsString('Editor Person Fixture', $data['message']);
        $this->assertStringContainsString('Fixture task', $data['message']);
    }

    public function test_mentions_ignore_case_and_work_for_the_owner_too(): void
    {
        $f = $this->teamFixture();

        $this->comment($f, 'editor', 'cc @olivia OWNER fixture');

        $this->assertSame([MentionedInCommentNotification::class => 1], $this->received($f->owner));
    }

    public function test_mentioning_someone_twice_notifies_them_once(): void
    {
        $f = $this->teamFixture();

        $this->comment($f, 'editor', '@Viewer Person Fixture, hello. Again: @Viewer Person Fixture.');

        $this->assertSame([MentionedInCommentNotification::class => 1], $this->received($f->viewer));
    }

    public function test_someone_outside_the_team_is_never_notified(): void
    {
        $f = $this->teamFixture();

        $this->comment($f, 'editor', 'Leaking this to @Otto Outsider Fixture');

        $this->assertSame([], $this->received($f->outsider));
        $this->assertSame(0, $f->outsider->notifications()->count());
    }

    public function test_a_longer_name_is_not_mistaken_for_the_shorter_one(): void
    {
        $f = $this->teamFixture();
        $short = User::factory()->create(['name' => 'Sam Fixture']);
        $long = User::factory()->create(['name' => 'Sam Fixture Longname']);
        $f->team->users()->attach($short, ['role' => 'editor']);
        $f->team->users()->attach($long, ['role' => 'editor']);

        $this->comment($f, 'editor', 'Over to @Sam Fixture Longname please');

        $this->assertSame(0, $short->notifications()->count());
        $this->assertSame(1, $long->notifications()->count());
    }

    public function test_you_are_not_notified_about_your_own_comment_or_mention(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'creator_id' => $f->editor->id]);

        $this->comment($f, 'editor', 'Note to self: @Editor Person Fixture');

        $this->assertSame(0, $f->editor->notifications()->count());
    }

    public function test_the_assignee_and_the_creator_each_get_one_notification(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'creator_id' => $f->admin->id]);

        $this->comment($f, 'owner', 'Any update on this?');

        $this->assertSame([NewCommentNotification::class => 1], $this->received($f->editor));
        $this->assertSame([NewCommentNotification::class => 1], $this->received($f->admin));
        $this->assertSame(0, $f->owner->notifications()->count(), 'the author is not told about their own comment');
        $this->assertSame(0, $f->viewer->notifications()->count(), 'nor is anyone uninvolved');
    }

    public function test_one_person_who_is_both_assignee_and_creator_is_notified_once(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'creator_id' => $f->editor->id]);

        $this->comment($f, 'admin', 'Looks good');

        $this->assertSame([NewCommentNotification::class => 1], $this->received($f->editor));
    }

    public function test_the_author_is_not_notified_when_they_are_the_assignee_or_the_creator(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'creator_id' => $f->admin->id]);

        $this->comment($f, 'editor', 'Working on it');

        $this->assertSame(0, $f->editor->notifications()->count());
        $this->assertSame([NewCommentNotification::class => 1], $this->received($f->admin));
    }

    public function test_someone_mentioned_who_is_also_the_assignee_gets_only_the_mention(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['assignee_id' => $f->editor->id, 'creator_id' => $f->editor->id]);

        $this->comment($f, 'admin', 'Over to you, @Editor Person Fixture');

        $this->assertSame([MentionedInCommentNotification::class => 1], $this->received($f->editor));
    }

    public function test_a_creator_who_has_left_the_team_is_not_notified(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['creator_id' => $f->admin->id]);
        $f->team->users()->detach($f->admin);

        $this->comment($f, 'editor', 'Anyone home?');

        $this->assertSame(0, $f->admin->notifications()->count());
    }

    public function test_comments_on_an_unassigned_task_by_its_creator_notify_nobody(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['creator_id' => $f->editor->id]);

        $this->comment($f, 'editor', 'Adding context for later');

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_the_comment_is_stored_as_plain_text_and_escaped_when_shown(): void
    {
        $f = $this->teamFixture();
        $f->team->users()->attach(User::factory()->create(['name' => 'Eve <b>Bold</b> Fixture']), ['role' => 'editor']);

        $drawer = $this->comment($f, 'editor', '<script>alert("pwned")</script> and @Eve <b>Bold</b> Fixture & more');

        $this->assertDatabaseHas('task_comments', ['body' => '<script>alert("pwned")</script> and @Eve <b>Bold</b> Fixture & more']);

        $drawer->assertDontSeeHtml('<script>alert("pwned")</script>')
            ->assertSeeHtml('&lt;script&gt;alert(&quot;pwned&quot;)&lt;/script&gt;')
            ->assertDontSeeHtml('<b>Bold</b>')
            ->assertSeeHtml('@Eve &lt;b&gt;Bold&lt;/b&gt; Fixture</span>');
    }

    public function test_a_mention_is_highlighted_in_the_thread_and_a_stranger_is_not(): void
    {
        $f = $this->teamFixture();

        $this->comment($f, 'editor', 'Thanks @Viewer Person Fixture and also @Otto Outsider Fixture')
            ->assertSeeHtml('@Viewer Person Fixture</span>')
            ->assertDontSeeHtml('@Otto Outsider Fixture</span>');
    }

    public function test_a_viewer_mentioned_in_a_comment_is_notified(): void
    {
        $f = $this->teamFixture();

        $this->comment($f, 'admin', '@Viewer Person Fixture fyi');

        $this->assertSame(1, $f->viewer->notifications()->count());
    }

    public function test_the_creator_is_recorded_when_a_task_is_created(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)
            ->test(CreateTask::class, ['taskList' => $f->list])
            ->set('title', 'Created by the editor')
            ->call('save');

        $this->assertSame($f->editor->id, Task::where('title', 'Created by the editor')->value('creator_id'));
    }

    public function test_the_notification_text_reaches_the_bell_escaped(): void
    {
        $f = $this->teamFixture();
        $f->editor->update(['name' => 'Eddie <u>Editor</u> Fixture']);
        $f->task->update(['assignee_id' => $f->admin->id]);

        $this->comment($f, 'editor', 'ping');

        Livewire::actingAs($f->admin)
            ->test(NotificationBell::class)
            ->call('toggle')
            ->assertSeeHtml('Eddie &lt;u&gt;Editor&lt;/u&gt; Fixture')
            ->assertDontSeeHtml('<u>Editor</u>');
    }
}
