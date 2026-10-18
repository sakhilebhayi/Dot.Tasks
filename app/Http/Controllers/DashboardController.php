<?php

namespace App\Http\Controllers;

use App\Services\DashboardStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats): View|RedirectResponse
    {
        // A user removed from their last team (or who never completed team
        // setup) reaches this route with currentTeam genuinely null — the
        // auth:sanctum/verified middleware group only checks that the user is
        // authenticated, not that they belong to a team. Without this guard,
        // $team->taskLists() throws "Attempt to read property 'id' on null".
        $team = $request->user()->currentTeam;

        if (! $team) {
            return redirect()->route('teams.create');
        }

        $lists = $team
            ->taskLists()
            ->withCount('tasks')
            ->orderBy('sort_order')
            ->get();

        return view('dashboard', [
            'lists' => $lists,
            'taskCounts' => $stats->forTeam($team),
        ]);
    }
}
