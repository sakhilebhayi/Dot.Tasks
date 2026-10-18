<?php

namespace App\Events;

use App\Models\TaskList;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast to everyone with a task list's board open whenever a task
 * moves -- so a second viewer sees the update live instead of only on
 * their next reload. Carries no task payload of its own; listeners just
 * re-fetch board state, which keeps this event valid regardless of which
 * action caused it (this is this platform's first app/Events class --
 * see wiki.md §4, which previously noted none existed).
 */
class TaskBoardUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly TaskList $taskList) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('task-list.'.$this->taskList->id)];
    }

    public function broadcastAs(): string
    {
        return 'board.updated';
    }
}
