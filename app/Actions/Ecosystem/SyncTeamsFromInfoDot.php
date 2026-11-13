<?php

namespace App\Actions\Ecosystem;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Jetstream;

/**
 * Applies the companies InfoDot says a person belongs to, on every sign-in.
 *
 * A company here is a team linked to an InfoDot team by uuid. Rules:
 * - Only people InfoDot lists as owner or admin of a company create its team here;
 *   an ordinary member is attached once it exists, so a member can never become
 *   the local owner of someone else's company.
 * - Membership and role follow InfoDot (owner and admin become admin, editor stays
 *   editor, anything else the least powerful role this app has).
 * - Companies InfoDot no longer lists for the person are detached, so removing
 *   someone in InfoDot removes their access here at their next sign-in.
 * - The company InfoDot says is active becomes the person's current team.
 * - Personal teams are never linked or removed.
 * - A company InfoDot marks as not holding this platform (`entitled` present and not true) is left exactly as it
 *   is: not created, not renamed, no membership or role change, not detached, and never made the current team.
 *   A reply with no `entitled` key (an older InfoDot) is treated as before.
 */
class SyncTeamsFromInfoDot
{
    /**
     * @param  array<string, mixed>  $identity  the redeem response
     */
    public function __invoke(User $user, array $identity): void
    {
        $listed = collect($identity['teams'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['uuid'] ?? null) && ! ($t['personal'] ?? false))
            ->keyBy('uuid');

        $admitted = $listed->filter(fn ($t) => CompanyEntitlement::admits($t));
        $refused = $listed->keys()->diff($admitted->keys())->all();

        // Said of the personal workspace at this sign-in; kept for the session (see EnsureCompanyEntitled).
        $personalRefused = collect($identity['teams'] ?? [])
            ->contains(fn ($t) => is_array($t) && ($t['personal'] ?? false) && ! CompanyEntitlement::admits($t));

        DB::transaction(function () use ($user, $listed, $admitted, $refused, $personalRefused, $identity) {
            foreach ($admitted as $uuid => $info) {
                $this->apply($user, (string) $uuid, $info);
                $this->forgetCachedTeams($user);
            }

            // Everything InfoDot lists is kept, entitled or not: a company without the platform keeps its people.
            $this->revoke($user, $listed->keys()->all());
            $this->forgetCachedTeams($user);

            $active = $identity['current_team_uuid'] ?? null;
            $team = $active && $admitted->has($active) ? Team::where('infodot_team_uuid', $active)->first() : null;

            if ($team && $user->belongsToTeam($team)) {
                $user->switchTeam($team);
            }

            $this->leaveRefusedWorkspace($user, $admitted->keys()->all(), $refused, $personalRefused);
        });
    }

    /**
     * A person whose current workspace InfoDot refused is moved to one it admitted: an admitted company they
     * belong to here, or failing that their personal workspace (unless that was the one refused).
     *
     * @param  list<string>  $admitted
     * @param  list<string>  $refused
     */
    private function leaveRefusedWorkspace(User $user, array $admitted, array $refused, bool $personalRefused): void
    {
        $this->forgetCachedTeams($user);
        $current = $user->fresh()?->currentTeam;

        $onRefused = $current && (
            in_array($current->infodot_team_uuid, $refused, true)
            || ($personalRefused && $current->personal_team && $current->user_id === $user->id)
        );

        if (! $onRefused) {
            return;
        }

        $teams = $user->allTeams();
        $target = $teams->first(fn ($team) => in_array($team->infodot_team_uuid, $admitted, true))
            ?? ($personalRefused ? null : $teams->first(fn ($team) => $team->personal_team && $team->user_id === $user->id));

        if ($target) {
            $user->switchTeam($target);
        }
    }

    /** Membership checks read relations cached on the model; reload after changing them. */
    private function forgetCachedTeams(User $user): void
    {
        $user->unsetRelation('teams')->unsetRelation('ownedTeams')->unsetRelation('currentTeam');
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function apply(User $user, string $uuid, array $info): void
    {
        $role = (string) ($info['role'] ?? '');
        $team = Team::where('infodot_team_uuid', $uuid)->first();

        if (! $team) {
            if (! in_array($role, ['owner', 'admin'], true)) {
                return;
            }

            $team = Team::forceCreate([
                'user_id' => $user->id,
                'name' => (string) ($info['name'] ?? 'Company'),
                'personal_team' => false,
                'infodot_team_uuid' => $uuid,
            ]);
        } elseif (filled($info['name'] ?? null) && $team->name !== $info['name']) {
            $team->forceFill(['name' => $info['name']])->save();
        }

        if ($team->user_id === $user->id) {
            return; // the local owner needs no membership row
        }

        $localRole = $this->localRole($role);

        if ($user->belongsToTeam($team)) {
            $team->users()->updateExistingPivot($user->id, ['role' => $localRole]);
        } else {
            $team->users()->attach($user->id, ['role' => $localRole]);
        }
    }

    /**
     * @param  list<string>  $keep
     */
    private function revoke(User $user, array $keep): void
    {
        $stale = $user->allTeams()->filter(fn ($team) => filled($team->infodot_team_uuid) && ! in_array($team->infodot_team_uuid, $keep, true));

        foreach ($stale as $team) {
            if ($team->user_id === $user->id) {
                // Cannot detach a team's owner; surface it instead of leaving it silent.
                Log::warning('InfoDot no longer lists a company this person owns locally.', ['team_id' => $team->id, 'user_id' => $user->id]);

                continue;
            }

            $team->users()->detach($user->id);

            if ($user->current_team_id === $team->id) {
                $user->forceFill(['current_team_id' => null])->save();
            }
        }
    }

    private function localRole(string $infodotRole): string
    {
        $wanted = in_array($infodotRole, ['owner', 'admin'], true) ? 'admin' : ($infodotRole === 'editor' ? 'editor' : 'viewer');

        return Jetstream::findRole($wanted) ? $wanted : 'editor';
    }
}
