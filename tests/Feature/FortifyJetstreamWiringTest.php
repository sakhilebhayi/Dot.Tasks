<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Jetstream\Http\Livewire\CreateTeamForm;
use Laravel\Jetstream\Http\Livewire\UpdateTeamNameForm;
use Laravel\Jetstream\Jetstream;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The app-level Fortify and Jetstream wiring (the Actions and the providers that bind them)
 * is what makes registration, team creation, profile and password updates, and the team
 * roles work at all. Nothing else in the suite signs a user up or edits a team, so this
 * file is what notices when that wiring goes missing again.
 */
class FortifyJetstreamWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_registers_gets_a_personal_team_and_is_logged_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => Jetstream::hasTermsAndPrivacyPolicyFeature(),
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertCount(1, $user->ownedTeams);

        $team = $user->ownedTeams->first();
        $this->assertTrue($team->personal_team);
        $this->assertSame("Test's Team", $team->name);
        $this->assertNull($team->infodot_team_uuid);
    }

    public function test_registration_rejects_a_duplicate_email_and_a_mismatched_password(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'name' => 'Someone', 'email' => 'taken@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'terms' => true,
        ])->assertSessionHasErrors('email');

        $this->post('/register', [
            'name' => 'Someone', 'email' => 'new@example.com',
            'password' => 'password', 'password_confirmation' => 'different',
            'terms' => true,
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNull(User::where('email', 'new@example.com')->first());
    }

    public function test_registration_cannot_set_the_infodot_team_uuid(): void
    {
        $this->post('/register', [
            'name' => 'Test User', 'email' => 'test@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'terms' => true,
            'infodot_team_uuid' => '11111111-1111-4111-8111-111111111111',
        ]);

        $this->assertNull(Team::first()->infodot_team_uuid);
    }

    public function test_a_user_can_log_in_and_out(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_team_can_be_created_through_the_jetstream_route(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $this->get(route('teams.create'))->assertOk();

        Livewire::test(CreateTeamForm::class)
            ->set(['state' => ['name' => 'Test Team']])
            ->call('createTeam');

        $this->assertCount(2, $user->fresh()->ownedTeams);

        $team = $user->fresh()->ownedTeams()->latest('id')->first();
        $this->assertSame('Test Team', $team->name);
        $this->assertFalse($team->personal_team);
        $this->assertNull($team->infodot_team_uuid);
        $this->assertSame($team->id, $user->fresh()->current_team_id);
    }

    public function test_a_team_owner_can_rename_the_team_and_a_member_cannot(): void
    {
        $this->actingAs($owner = User::factory()->withPersonalTeam()->create());
        $team = $owner->currentTeam ?? $owner->ownedTeams->first();

        Livewire::test(UpdateTeamNameForm::class, ['team' => $team])
            ->set(['state' => ['name' => 'Renamed']])
            ->call('updateTeamName');

        $this->assertSame('Renamed', $team->fresh()->name);

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => 'editor']);
        $this->actingAs($member);

        Livewire::test(UpdateTeamNameForm::class, ['team' => $team->fresh()])
            ->set(['state' => ['name' => 'Hijacked']])
            ->call('updateTeamName')
            ->assertForbidden();

        $this->assertSame('Renamed', $team->fresh()->name);
    }

    public function test_the_password_can_be_updated_and_the_current_one_must_be_correct(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $this->put('/user/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->put('/user/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $this->put('/user/profile-information', [
            'name' => 'Test Name',
            'email' => 'test@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Test Name', $user->fresh()->name);
        $this->assertSame('test@example.com', $user->fresh()->email);
    }

    public function test_profile_update_rejects_another_users_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $this->actingAs($user = User::factory()->withPersonalTeam()->create());

        $this->put('/user/profile-information', [
            'name' => 'Test Name',
            'email' => 'taken@example.com',
        ])->assertSessionHasErrorsIn('updateProfileInformation', 'email');

        $this->assertNotSame('taken@example.com', $user->fresh()->email);
    }

    public function test_the_admin_and_editor_roles_are_defined(): void
    {
        $admin = Jetstream::findRole('admin');
        $editor = Jetstream::findRole('editor');

        $this->assertNotNull($admin, 'Jetstream role "admin" is not defined');
        $this->assertNotNull($editor, 'Jetstream role "editor" is not defined');
        $this->assertSame(['create', 'read', 'update', 'delete'], $admin->permissions);
        $this->assertSame(['read', 'create', 'update'], $editor->permissions);
    }
}
