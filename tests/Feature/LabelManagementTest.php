<?php

namespace Tests\Feature;

use App\Livewire\Labels\LabelManager;
use App\Models\Label;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class LabelManagementTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    public function test_every_team_member_can_see_the_label_page(): void
    {
        $f = $this->teamFixture();

        foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
            $this->actingAs($f->{$role})
                ->get(route('labels.index'))
                ->assertOk()
                ->assertSee('Fixture label');
        }
    }

    public function test_an_admin_creates_a_label_with_a_palette_colour(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)
            ->test(LabelManager::class)
            ->set('name', 'Customer escalation')
            ->set('color', '#ef4444')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('labels', [
            'team_id' => $f->team->id,
            'name' => 'Customer escalation',
            'color' => '#ef4444',
        ]);
    }

    public function test_the_team_owner_can_manage_labels_too(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->owner)
            ->test(LabelManager::class)
            ->set('name', 'Owner label')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('labels', ['team_id' => $f->team->id, 'name' => 'Owner label']);
    }

    public function test_a_colour_outside_the_palette_is_rejected(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)
            ->test(LabelManager::class)
            ->set('name', 'Off palette')
            ->set('color', '#123456')
            ->call('create')
            ->assertHasErrors(['color']);

        $this->assertDatabaseMissing('labels', ['name' => 'Off palette']);
    }

    public function test_a_blank_or_overlong_name_is_rejected_and_names_are_unique_per_team(): void
    {
        $f = $this->teamFixture();

        $page = Livewire::actingAs($f->admin)->test(LabelManager::class);

        $page->set('name', '')->call('create')->assertHasErrors(['name']);
        $page->set('name', str_repeat('x', 61))->call('create')->assertHasErrors(['name']);
        $page->set('name', 'fixture LABEL')->call('create')->assertHasErrors(['name']);

        $this->assertSame(1, Label::count());

        // The same name on another team is fine.
        $other = User::factory()->withPersonalTeam()->create();
        Label::create(['team_id' => $other->currentTeam->id, 'name' => 'Shared name', 'color' => '#22c55e']);
        $page->set('name', 'Shared name')->call('create')->assertHasNoErrors();
        $this->assertSame(1, Label::where('team_id', $f->team->id)->where('name', 'Shared name')->count());
    }

    public function test_an_admin_renames_and_recolours_a_label(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)
            ->test(LabelManager::class)
            ->call('startEdit', $f->label->id)
            ->assertSet('editName', 'Fixture label')
            ->set('editName', 'Renamed label')
            ->set('editColor', '#3b82f6')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $label = $f->label->fresh();
        $this->assertSame('Renamed label', $label->name);
        $this->assertSame('#3b82f6', $label->color);
    }

    public function test_renaming_a_label_to_its_own_name_is_not_a_duplicate(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)
            ->test(LabelManager::class)
            ->call('startEdit', $f->label->id)
            ->set('editColor', '#14b8a6')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('#14b8a6', $f->label->fresh()->color);
    }

    public function test_an_admin_deletes_a_label_and_it_leaves_its_tasks(): void
    {
        $f = $this->teamFixture();
        $f->task->labels()->attach($f->label->id);

        Livewire::actingAs($f->admin)
            ->test(LabelManager::class)
            ->call('delete', $f->label->id);

        $this->assertDatabaseMissing('labels', ['id' => $f->label->id]);
        $this->assertDatabaseMissing('task_label', ['label_id' => $f->label->id]);
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id]);
    }

    public function test_an_editor_and_a_viewer_cannot_create_rename_or_delete_labels(): void
    {
        foreach (['editor', 'viewer'] as $role) {
            $f = $this->teamFixture();
            // A fresh component per attempt: one that has aborted cannot be driven further.
            $page = fn () => Livewire::actingAs($f->{$role})->test(LabelManager::class);

            $page()->set('name', 'Not allowed')->call('create')->assertForbidden();
            $page()->call('startEdit', $f->label->id)->assertForbidden();
            $page()->call('delete', $f->label->id)->assertForbidden();

            $this->assertDatabaseMissing('labels', ['name' => 'Not allowed']);
            $this->assertDatabaseHas('labels', ['id' => $f->label->id, 'name' => 'Fixture label']);
        }
    }

    public function test_the_page_hides_the_management_controls_from_those_who_cannot_use_them(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(LabelManager::class)
            ->assertSee('Fixture label')
            ->assertDontSee('New label');

        Livewire::actingAs($f->admin)->test(LabelManager::class)
            ->assertSee('New label');
    }

    public function test_a_label_belonging_to_another_team_cannot_be_touched(): void
    {
        $f = $this->teamFixture();
        $otherOwner = User::factory()->withPersonalTeam()->create();
        $foreign = Label::create([
            'team_id' => $otherOwner->currentTeam->id,
            'name' => 'Foreign label',
            'color' => '#22c55e',
        ]);

        $page = fn () => Livewire::actingAs($f->admin)->test(LabelManager::class);

        $page()->assertDontSee('Foreign label');
        $page()->call('startEdit', $foreign->id)->assertNotFound();
        $page()->call('delete', $foreign->id)->assertNotFound();

        $this->assertDatabaseHas('labels', ['id' => $foreign->id, 'name' => 'Foreign label']);
    }

    public function test_labels_are_only_listed_for_the_current_team(): void
    {
        $f = $this->teamFixture();
        $otherOwner = User::factory()->withPersonalTeam()->create();
        Label::create(['team_id' => $otherOwner->currentTeam->id, 'name' => 'Other team secret label', 'color' => '#22c55e']);

        $this->actingAs($f->viewer)
            ->get(route('labels.index'))
            ->assertOk()
            ->assertDontSee('Other team secret label');
    }

    public function test_the_palette_is_a_fixed_list_of_hex_colours(): void
    {
        $this->assertNotEmpty(Label::PALETTE);
        foreach (Label::PALETTE as $name => $hex) {
            $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex, $name);
        }
        $this->assertContains('#6366f1', Label::PALETTE, 'the column default must be a valid palette colour');
    }
}
