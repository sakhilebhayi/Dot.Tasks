<?php

namespace App\Services;

use App\Models\Label;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Support\Today;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Reads a plain question into a search, with rules: "Show me the maintenance tasks assigned to my team that are overdue."
 * It says what it understood, and whatever it could not place stays as words to look for.
 */
class TaskSearchReader
{
    private const FILLER = ['show', 'me', 'find', 'get', 'list', 'give', 'the', 'a', 'an', 'all', 'any', 'tasks', 'task', 'work', 'that', 'which', 'are', 'is', 'was', 'were', 'of', 'on', 'in', 'for', 'with', 'and', 'please', 'what', 'where', 'to', 'my', 'our'];

    /**
     * @return array{words: string, understood: list<string>, apply: list<callable(Builder<Task>): mixed>}
     */
    public function read(string $question, Team $team, User $me): array
    {
        $text = ' '.Str::lower(trim(preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $question))).' ';
        $apply = [];
        $understood = [];

        $take = function (string $pattern) use (&$text): ?array {
            if (preg_match($pattern, $text, $match)) {
                $text = str_replace($match[0], ' ', $text);

                return $match;
            }

            return null;
        };

        if ($take('/\s(?:overdue|late|past due)\s/')) {
            $understood[] = 'Overdue';
            $apply[] = fn (Builder $q) => $q->dueState('overdue');
        } elseif ($take('/\sdue today\s/')) {
            $understood[] = 'Due today';
            $apply[] = fn (Builder $q) => $q->whereDate('tasks.due_date', Today::date());
        } elseif ($take('/\sdue tomorrow\s/')) {
            $understood[] = 'Due tomorrow';
            $apply[] = fn (Builder $q) => $q->whereDate('tasks.due_date', Today::date()->addDay());
        } elseif ($take('/\sdue (?:this|in the next) week\s/')) {
            $understood[] = 'Due this week';
            $apply[] = fn (Builder $q) => $q->whereDate('tasks.due_date', '>=', Today::date())->whereDate('tasks.due_date', '<=', Today::date()->addDays(7));
        }

        if ($take('/\s(?:done|finished|completed)\s/')) {
            $understood[] = 'Finished';
            $apply[] = fn (Builder $q) => $q->where('tasks.status', 'done');
        } elseif ($take('/\s(?:open|unfinished|outstanding)\s/')) {
            $understood[] = 'Open';
            $apply[] = fn (Builder $q) => $q->where('tasks.status', '!=', 'done');
        } elseif ($take('/\sin review\s/')) {
            $understood[] = 'In review';
            $apply[] = fn (Builder $q) => $q->where('tasks.status', 'review');
        } elseif ($take('/\sin progress\s/')) {
            $understood[] = 'In progress';
            $apply[] = fn (Builder $q) => $q->where('tasks.status', 'in_progress');
        }

        if ($take('/\sblocked\s/')) {
            $understood[] = 'Blocked';
            $apply[] = fn (Builder $q) => $q->where('tasks.status', '!=', 'done')->whereHas('blockedBy', fn ($b) => $b->where('status', '!=', 'done'));
        }

        foreach (['urgent', 'high', 'low'] as $priority) {
            if ($take('/\s'.$priority.'(?: priority)?\s/')) {
                $understood[] = ucfirst($priority).' priority';
                $apply[] = fn (Builder $q) => $q->where('tasks.priority', $priority);
                break;
            }
        }

        if ($take('/\s(?:assigned to (?:me|myself)|my tasks|mine)\s/')) {
            $understood[] = 'Yours';
            $apply[] = fn (Builder $q) => $q->where('tasks.assignee_id', $me->id);
        } elseif ($take('/\s(?:assigned to (?:my |the |our )?team)\s/')) {
            $understood[] = 'Given to someone on the team';
            $apply[] = fn (Builder $q) => $q->whereNotNull('tasks.assignee_id');
        } elseif ($take('/\s(?:unassigned|not assigned|nobody on)\s/')) {
            $understood[] = 'Nobody on it';
            $apply[] = fn (Builder $q) => $q->whereNull('tasks.assignee_id');
        } elseif ($match = $take('/\s(?:assigned to|given to|for|by)\s+([\p{L}\'-]+(?:\s+[\p{L}\'-]+)?)\s/u')) {
            $person = $this->person($match[1], $team);
            if ($person) {
                $understood[] = 'Given to '.$person->name;
                $apply[] = fn (Builder $q) => $q->where('tasks.assignee_id', $person->id);
            } else {
                $text .= ' '.$match[1].' ';
            }
        }

        $words = collect(preg_split('/\s+/', trim($text)))->filter()->reject(fn (string $word) => in_array($word, self::FILLER, true))->values();

        $labels = Label::where('team_id', $team->id)->get(['id', 'name'])->keyBy(fn (Label $label) => Str::lower($label->name));
        $words = $words->reject(function (string $word) use ($labels, &$understood, &$apply) {
            $label = $labels->get($word);
            if (! $label) {
                return false;
            }

            $understood[] = 'Label '.$label->name;
            $apply[] = fn (Builder $q) => $q->whereHas('labels', fn ($l) => $l->where('labels.id', $label->id));

            return true;
        })->values();

        return ['words' => $words->implode(' '), 'understood' => $understood, 'apply' => $apply];
    }

    private function person(string $words, Team $team): ?User
    {
        $members = $team->allUsers();

        foreach ([trim($words), Str::before(trim($words), ' ')] as $name) {
            $found = $members->filter(fn (User $user) => Str::lower($user->name) === $name || Str::lower(Str::before($user->name, ' ')) === $name);
            if ($found->count() === 1) {
                return $found->first();
            }
        }

        return null;
    }
}
