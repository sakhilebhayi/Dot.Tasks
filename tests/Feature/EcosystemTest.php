<?php

namespace Tests\Feature;

use App\Livewire\Integrations;
use App\Livewire\Tasks\TaskDetail;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Brief §15: connections that say what they are, and do not appear to work when they do not. */
class EcosystemTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.files.url' => 'https://files.test', 'services.files.secret' => 'shared-secret', 'services.files.platform' => 'tasks']);
    }

    private function attached(object $f): TaskAttachment
    {
        $f->team->forceFill(['infodot_team_uuid' => 'company-1'])->save();
        Livewire::actingAs($f->editor)->test(TaskDetail::class)->call('openTask', $f->task->id)
            ->set('upload', UploadedFile::fake()->create('plan.pdf', 10, 'application/pdf'));

        return TaskAttachment::sole();
    }

    private function panel(object $f, string $who = 'editor')
    {
        return Livewire::actingAs($f->{$who})->test(TaskDetail::class)->call('openTask', $f->task->id);
    }

    public function test_a_file_is_kept_in_dot_files_as_a_signed_call_and_the_link_comes_back(): void
    {
        $f = $this->teamFixture();
        $file = $this->attached($f);
        Http::fake(['files.test/*' => Http::response(['uuid' => 'abc', 'open_url' => 'https://files.test/d/abc'])]);

        $this->panel($f)->call('keepInFiles', $file->id)->assertHasNoErrors()->assertSee('In Dot.Files');

        $this->assertSame('abc', $file->fresh()->file_uuid);
        $this->assertSame('https://files.test/d/abc', $file->fresh()->open_url);
        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $signed = 'sha256='.hash_hmac('sha256', $request->header('X-Dot-Timestamp')[0].'.'.$request->body(), 'shared-secret');

            return $request->url() === 'https://files.test/api/platform/files/store'
                && $request->header('X-Dot-Platform') === ['tasks']
                && $request->header('X-Dot-Signature') === [$signed]
                && $body['team_uuid'] === 'company-1'
                && $body['folder'] === 'Dot.Tasks'
                && $body['name'] === 'plan.pdf';
        });
    }

    /** @return array<string, array{string}> */
    public static function badLinks(): array
    {
        return ['another host' => ['https://evil.test/x'], 'not secure' => ['http://files.test/d/abc'], 'script' => ['javascript:alert(1)']];
    }

    #[DataProvider('badLinks')]
    public function test_a_link_that_does_not_go_where_it_should_is_not_passed_on(string $url): void
    {
        $f = $this->teamFixture();
        $file = $this->attached($f);
        Http::fake(['files.test/*' => Http::response(['uuid' => 'abc', 'open_url' => $url])]);

        $this->panel($f)->call('keepInFiles', $file->id);

        $this->assertNull($file->fresh()->open_url);
    }

    /** @return array<string, array{int, string}> */
    public static function refusals(): array
    {
        return [
            'wrong secret' => [401, 'did not accept our signature'],
            'too large' => [413, 'larger than the 25 MB'],
            'busy' => [429, 'Dot.Files is busy'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_refusal_is_said_plainly_and_nothing_is_marked(int $status, string $words): void
    {
        $f = $this->teamFixture();
        $file = $this->attached($f);
        Http::fake(['files.test/*' => Http::response([], $status)]);

        $this->panel($f)->call('keepInFiles', $file->id)->assertHasErrors('files')->assertSee($words);

        $this->assertNull($file->fresh()->file_uuid);
    }

    public function test_an_unreachable_service_is_said_plainly(): void
    {
        $f = $this->teamFixture();
        $file = $this->attached($f);
        Http::fake(fn () => throw new ConnectionException('down'));

        $this->panel($f)->call('keepInFiles', $file->id)->assertSee('could not be reached');
    }

    public function test_a_business_with_no_company_link_or_no_setup_gets_a_reason_and_no_call(): void
    {
        $f = $this->teamFixture();
        $file = $this->attached($f);
        Http::fake();

        $f->team->forceFill(['infodot_team_uuid' => null])->save();
        $this->panel($f)->call('keepInFiles', $file->id)->assertSee('not linked to its InfoDot company');

        config(['services.files.secret' => '']);
        $this->panel($f)->assertDontSee('Keep in Dot.Files');

        Http::assertNothingSent();
    }

    public function test_a_file_is_only_kept_once_and_only_by_someone_who_can_edit(): void
    {
        $f = $this->teamFixture();
        $file = $this->attached($f);
        $file->update(['file_uuid' => 'abc']);
        Http::fake();

        $this->panel($f)->call('keepInFiles', $file->id)->assertHasErrors('files');
        $this->panel($f, 'viewer')->call('keepInFiles', $file->id)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_file_on_another_task_cannot_be_reached_from_this_one(): void
    {
        $f = $this->teamFixture();
        $other = $f->list->tasks()->create(['title' => 'Other', 'status' => 'todo']);
        $file = $other->attachments()->create(['path' => 'x', 'name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);

        $this->panel($f)->call('keepInFiles', $file->id)->assertNotFound();
    }

    public function test_only_admins_see_connections_and_it_never_claims_a_link_works(): void
    {
        $f = $this->teamFixture();
        config(['services.notify.url' => '', 'services.notify.secret' => '', 'services.files.secret' => '']);

        config(['services.analytics.url' => '', 'services.analytics.secret' => '']);
        $this->actingAs($f->admin)->get(route('tasks.connections'))->assertOk()->assertSee('Not set up')->assertSee('It does not prove');
        $this->actingAs($f->editor)->get(route('tasks.connections'))->assertForbidden();
        Livewire::actingAs($f->admin)->test(Integrations::class)->assertSee('not linked to an InfoDot company')->assertDontSeeHtml('chip go');

        config(['services.files.secret' => 'shared-secret']);
        Livewire::actingAs($f->admin)->test(Integrations::class)->assertSeeInOrder(['Dot.Files', 'Set up']);
    }

    public function test_analytics_counts_what_was_finished_this_week(): void
    {
        $f = $this->teamFixture();
        $f->list->tasks()->create(['title' => 'Recent', 'status' => 'done', 'completed_at' => now()->subDays(2)]);
        $f->list->tasks()->create(['title' => 'Old', 'status' => 'done', 'completed_at' => now()->subDays(20)]);

        $this->assertSame(1, app(MetricsCollector::class)->collect($f->team)['completed_7d']);
    }

    public function test_a_task_links_to_its_project_page(): void
    {
        $f = $this->teamFixture();

        $this->panel($f)->call('saveField', 'project_url', 'https://projects.infodot.co.za/p/4')->assertHasNoErrors();
        $this->assertSame('https://projects.infodot.co.za/p/4', $f->task->fresh()->project_url);

        $this->panel($f, 'viewer')->assertSee('Open the project');
    }

    public function test_a_project_link_has_to_be_a_secure_address(): void
    {
        $f = $this->teamFixture();

        foreach (['javascript:alert(1)', 'http://plain.test', 'not a link'] as $bad) {
            $this->panel($f)->call('saveField', 'project_url', $bad)->assertHasErrors('project_url');
        }

        $this->assertNull(Task::find($f->task->id)->project_url);
    }
}
