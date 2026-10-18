<?php

namespace Tests\Feature;

use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/**
 * Role by action. Owner and admin do everything, an editor works on tasks but
 * cannot delete or manage labels, a viewer reads, and a user from another team
 * does nothing at all.
 */
class TaskRolesPolicyTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    public function test_jetstream_has_a_read_only_viewer_role(): void
    {
        $viewer = Jetstream::findRole('viewer');

        $this->assertNotNull($viewer);
        $this->assertSame(['read'], $viewer->permissions);
        $this->assertSame(['admin', 'editor', 'viewer'], array_keys(Jetstream::$roles));
    }

    /**
     * ability => [target kind, roles allowed]
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function matrix(): array
    {
        $all = ['owner', 'admin', 'editor', 'viewer'];
        $workers = ['owner', 'admin', 'editor'];
        $admins = ['owner', 'admin'];

        return [
            'task view' => ['task', 'view', $all],
            'task create' => ['task-create', 'create', $workers],
            'task update' => ['task', 'update', $workers],
            'task move' => ['task', 'move', $workers],
            'task comment' => ['task', 'comment', $workers],
            'task assign' => ['task', 'assign', $workers],
            'task label' => ['task', 'label', $workers],
            'task delete' => ['task', 'delete', $admins],
            'board view' => ['list', 'view', $all],
            'board create' => ['list-create', 'create', $workers],
            'board update' => ['list', 'update', $workers],
            'board delete' => ['list', 'delete', $admins],
            'label list' => ['label-any', 'viewAny', $all],
            'label create' => ['label-create', 'create', $admins],
            'label update' => ['label', 'update', $admins],
            'label delete' => ['label', 'delete', $admins],
            'label assign' => ['label', 'assign', $workers],
        ];
    }

    /**
     * @param  list<string>  $allowedRoles
     */
    #[DataProvider('matrix')]
    public function test_each_role_gets_exactly_the_actions_it_should(string $kind, string $ability, array $allowedRoles): void
    {
        $f = $this->teamFixture();

        // Board and label creation act on the user's *current* team, and the
        // outsider's current team is their own personal one, where they rightly
        // can. Their lack of access to this team is covered by the other rows.
        $roles = in_array($kind, ['list-create', 'label-any'], true)
            ? ['owner', 'admin', 'editor', 'viewer']
            : ['owner', 'admin', 'editor', 'viewer', 'outsider'];

        foreach ($roles as $role) {
            $user = $f->{$role};
            $gate = Gate::forUser($user);

            $arguments = match ($kind) {
                'task' => $f->task,
                'task-create' => [Task::class, $f->list],
                'list' => $f->list,
                'list-create' => TaskList::class,
                'label' => $f->label,
                'label-any' => Label::class,
                'label-create' => [Label::class, $f->team],
            };

            $this->assertSame(
                in_array($role, $allowedRoles, true),
                $gate->allows($ability, $arguments),
                "{$role} / {$kind} / {$ability}"
            );
        }
    }

    public function test_the_escalation_decision_needs_the_update_permission_as_well_as_list_ownership(): void
    {
        $f = $this->teamFixture();

        // A viewer who somehow owns the list still cannot decide on escalations.
        $f->list->update(['owner_id' => $f->viewer->id]);

        $this->assertFalse(Gate::forUser($f->viewer)->allows('escalate', $f->task->fresh()));

        $f->list->update(['owner_id' => $f->editor->id]);
        $this->assertTrue(Gate::forUser($f->editor)->allows('escalate', $f->task->fresh()));
    }
}
