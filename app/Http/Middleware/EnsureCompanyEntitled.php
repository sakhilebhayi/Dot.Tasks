<?php

namespace App\Http\Middleware;

use App\Services\InfoDot\CompanyEntitlement;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in person keeps working only while their current company still holds this platform at InfoDot.
 *
 * Runs on the signed-in area: every route behind the auth middleware, plus the Livewire and broadcasting endpoints
 * when someone is signed in. Sign-in, sign-out, the handoff receiver, switching company, health checks, the
 * machine API and every public page are left alone. A company InfoDot says does not hold the platform gets the
 * plain refusal page with a 403; nothing behind it runs, so no data is read or changed. No clear answer from
 * InfoDot means carry on (see CompanyEntitlement).
 */
class EnsureCompanyEntitled
{
    /** Signed-in routes a refused person still needs: leaving, and moving to another of their companies. */
    private const ALWAYS_OPEN = ['logout', 'current-team.update', 'ecosystem.auth'];

    /** Endpoints with no auth middleware of their own that read or change a signed-in person's data. */
    private const SIGNED_IN_ENDPOINTS = ['livewire/update', 'livewire/upload-file', 'livewire/preview-file/*', 'broadcasting/auth'];

    public function __construct(private CompanyEntitlement $entitlement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlement->enabled() || ! $this->guarded($request)) {
            return $next($request);
        }

        $user = $request->user();
        $team = $user?->currentTeam;

        if ($user === null || $team === null) {
            return $next($request);
        }

        // A personal workspace, or any team with no InfoDot company, is never asked about. Individuals hold the free
        // core only, so what redeem said of the personal workspace at this sign-in decides: exactly false is refused;
        // true, or nothing remembered (an older InfoDot, a session from before this release), carries on.
        if ($team->personal_team || blank($team->infodot_team_uuid)) {
            $remembered = $request->hasSession() ? $request->session()->get(CompanyEntitlement::PERSONAL_SESSION_KEY) : null;

            return $remembered === false ? $this->refuse($request, 'company_required', personal: true) : $next($request);
        }

        $answer = $this->entitlement->of((string) $team->infodot_team_uuid);

        if ($answer === null || $answer['entitled']) {
            return $next($request);
        }

        return $this->refuse($request, $answer['status']);
    }

    private function guarded(Request $request): bool
    {
        $route = $request->route();

        if ($route === null || in_array($route->getName(), self::ALWAYS_OPEN, true)) {
            return false;
        }

        if ($request->is(...self::SIGNED_IN_ENDPOINTS)) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:') || str_starts_with($middleware, Authenticate::class))) {
                return true;
            }
        }

        return false;
    }

    private function localAllowed(Request $request): bool
    {
        return ! $request->hasSession() || $request->session()->get(CompanyEntitlement::PERSONAL_SESSION_KEY) !== false;
    }

    private function refuse(Request $request, string $status, bool $personal = false): Response
    {
        $user = $request->user();
        $current = $user->currentTeam;

        return response()->view('errors.entitlement', [
            'status' => $status,
            'company' => $personal ? null : $current->name,
            'manageUrl' => $this->entitlement->manageUrl(),
            // The person's own other workspaces, by name only: nothing of theirs is read to draw this page.
            // Teams with no InfoDot company are not offered when the personal answer rules them out.
            'others' => $user->allTeams()
                ->reject(fn ($team) => $team->id === $current->id)
                ->filter(fn ($team) => filled($team->infodot_team_uuid) || $this->localAllowed($request))
                ->values(),
            'signedIn' => true,
        ], 403)->header('Cache-Control', 'no-store, private');
    }
}
