<?php

namespace App\Http\Middleware;

use App\Support\Today;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every date on a page is read in the time zone of the team it belongs to. */
class UseTeamClock
{
    public function handle(Request $request, Closure $next): Response
    {
        Today::use($request->user()?->currentTeam?->timezone);

        return $next($request);
    }
}
