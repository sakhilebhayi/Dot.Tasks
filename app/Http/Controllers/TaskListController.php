<?php

namespace App\Http\Controllers;

use App\Models\Label;
use App\Models\TaskList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskListController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        // A user removed from their last team (or who never completed team
        // setup) can still reach this action — auth:sanctum/verified only
        // requires authentication, not team membership — so currentTeam can
        // genuinely be null here. Guard before dereferencing ->id, mirroring
        // the ecosystem-wide "Attempt to read property 'id' on null" fix.
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'You must belong to a team to create a task list.');
        $this->authorize('create', TaskList::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'nullable|string|max:7',
        ]);

        $list = TaskList::create([
            'team_id' => $team->id,
            'owner_id' => $request->user()->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? '#6366f1',
        ]);

        return redirect()->route('task-lists.show', $list);
    }

    public function show(Request $request, TaskList $taskList): View
    {
        $this->authorize('view', $taskList);

        $labels = Label::where('team_id', $taskList->team_id)->get();

        return view('task-lists.show', compact('taskList', 'labels'));
    }
}
