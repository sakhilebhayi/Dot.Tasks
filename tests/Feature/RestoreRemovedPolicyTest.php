<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\Concerns\RemovesTasks;
use Tests\TestCase;

/**
 * Bringing a deleted task back is as strong an act as deleting it: owner and
 * admin only. (Restoring an ARCHIVED task is a separate, editor-level ability
 * and is not what this covers.)
 */
class RestoreRemovedPolicyTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;
    use RemovesTasks;

    /** @return array<string, array{0: string, 1: bool, 2: bool}> */
    public static function matrix(): array
    {
        $cases = [];
        foreach (['owner' => true, 'admin' => true, 'editor' => false, 'viewer' => false, 'outsider' => false] as $role => $allowed) {
            foreach ([false, true] as $archivedBoard) {
                $cases["$role ".($allowed ? 'can' : 'cannot').' restore a deleted task'.($archivedBoard ? ' on an archived board' : '')] = [$role, $archivedBoard, $allowed];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_restoring_a_deleted_task_is_owner_and_admin_only(string $role, bool $archivedBoard, bool $allowed): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        if ($archivedBoard) {
            $f->list->archive();
        }
        $removed = Task::withoutGlobalScopes()->find($f->task->id);

        $this->assertSame($allowed, Gate::forUser($f->{$role})->allows('restoreDeleted', $removed));
    }

    public function test_the_existing_archive_restore_ability_is_unchanged_for_editors(): void
    {
        $f = $this->teamFixture();

        $this->assertTrue(Gate::forUser($f->editor)->allows('restore', $f->task));
        $this->assertFalse(Gate::forUser($f->viewer)->allows('restore', $f->task));
    }
}
