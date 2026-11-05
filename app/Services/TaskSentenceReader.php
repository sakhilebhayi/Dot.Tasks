<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads a sentence into a task, with rules, so it works with no AI and costs nothing:
 * "Prepare the monthly operational report for Friday and assign it to James."
 * It only suggests: the person sees what was understood and confirms it before anything is created.
 */
class TaskSentenceReader
{
    private const DAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

    private const MONTHS = ['january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /**
     * @return array{title: string, due_date: string|null, priority: string|null, assignee_id: int|null, assignee_name: string|null, unknown_person: string|null}
     */
    public function read(string $sentence, Team $team, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy()->startOfDay();
        $text = ' '.trim($sentence).' ';

        [$text, $assignee, $unknown] = $this->person($text, $team);
        [$text, $priority] = $this->priority($text);
        [$text, $due] = $this->date($text, $now);

        return [
            'title' => $this->tidy($text),
            'due_date' => $due?->toDateString(),
            'priority' => $priority,
            'assignee_id' => $assignee?->id,
            'assignee_name' => $assignee?->name,
            'unknown_person' => $unknown,
        ];
    }

    /** @return array{0: string, 1: User|null, 2: string|null} */
    private function person(string $text, Team $team): array
    {
        $members = $team->allUsers();

        // "assign it to James", "assign to James Dlamini", "give it to James", "@James"
        if (preg_match('/\s(?:and\s+)?(?:assign(?:ed)?|give|hand)(?:\s+(?:it|this))?\s+(?:to\s+)@?([\p{L}\'-]+(?:\s+[\p{L}\'-]+)?)/iu', $text, $match)
            || preg_match('/\s@([\p{L}\'-]+(?:\s+[\p{L}\'-]+)?)/u', $text, $match)) {
            [$user, $used] = $this->match($match[1], $members);
            $phrase = $used === null ? $match[0] : substr($match[0], 0, strrpos($match[0], $match[1])).$used;

            return [str_replace($phrase, ' ', $text), $user, $user ? null : trim($match[1])];
        }

        return [$text, null, null];
    }

    /**
     * The fullest name that fits: "James Dlamini", then "James". Two people called James is a question, not a guess.
     *
     * @param  Collection<int, User>  $members
     * @return array{0: User|null, 1: string|null} the person, and the words of the sentence that named them
     */
    private function match(string $words, Collection $members): array
    {
        $parts = preg_split('/\s+/', trim($words)) ?: [];

        foreach ([implode(' ', $parts), $parts[0] ?? ''] as $candidate) {
            $needle = mb_strtolower($candidate);
            $found = $members->filter(fn (User $user) => mb_strtolower($user->name) === $needle
                || mb_strtolower((string) strtok($user->name, ' ')) === $needle);

            if ($found->count() === 1) {
                return [$found->first(), $candidate];
            }
        }

        return [null, null];
    }

    /** @return array{0: string, 1: string|null} */
    private function priority(string $text): array
    {
        foreach (['urgent' => '/\s(?:urgent|urgently|asap)\b[,.!]?/i', 'high' => '/\shigh(?:\s+priority)\b/i', 'low' => '/\slow(?:\s+priority)\b/i'] as $priority => $pattern) {
            if (preg_match($pattern, $text, $match)) {
                return [str_replace($match[0], ' ', $text), $priority];
            }
        }

        return [$text, null];
    }

    /** @return array{0: string, 1: Carbon|null} */
    private function date(string $text, Carbon $now): array
    {
        $days = implode('|', array_keys(self::DAYS));
        $months = implode('|', array_keys(self::MONTHS));
        $lead = '\s(?:(?:due|for|by|on|before|this)\s+)?';

        $rules = [
            '/'.$lead.'today\b/i' => fn () => $now->copy(),
            '/'.$lead.'tonight\b/i' => fn () => $now->copy(),
            '/'.$lead.'tomorrow\b/i' => fn () => $now->copy()->addDay(),
            '/'.$lead.'next\s+week\b/i' => fn () => $now->copy()->next(Carbon::MONDAY),
            '/'.$lead.'end\s+of\s+(?:the\s+)?week\b/i' => fn () => $now->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay()->subDays(2),
            '/'.$lead.'end\s+of\s+(?:the\s+)?month\b/i' => fn () => $now->copy()->endOfMonth()->startOfDay(),
            '/\sin\s+(\d{1,3})\s+days?\b/i' => fn ($m) => $now->copy()->addDays((int) $m[1]),
            '/\sin\s+(\d{1,2})\s+weeks?\b/i' => fn ($m) => $now->copy()->addWeeks((int) $m[1]),
            '/'.$lead.'next\s+('.$days.')\b/i' => fn ($m) => $this->weekday($now, $m[1])->addWeek(),
            // A weekday alone counts only with a lead word or at the very end: "Plan the Friday market" has no date in it.
            '/\s(?:due|for|by|on|before|this)\s+('.$days.')\b/i' => fn ($m) => $this->weekday($now, $m[1]),
            '/\s('.$days.')[.!]?\s*$/i' => fn ($m) => $this->weekday($now, $m[1]),
            '/'.$lead.'(\d{1,2})(?:st|nd|rd|th)?\s+('.$months.')\b/i' => fn ($m) => $this->dayMonth($now, (int) $m[1], $m[2]),
            '/'.$lead.'('.$months.')\s+(\d{1,2})(?:st|nd|rd|th)?\b/i' => fn ($m) => $this->dayMonth($now, (int) $m[2], $m[1]),
        ];

        foreach ($rules as $pattern => $make) {
            if (preg_match($pattern, $text, $match)) {
                $date = $make($match);

                return [$date === null ? $text : str_replace($match[0], ' ', $text), $date];
            }
        }

        return [$text, null];
    }

    /** The next one of that day, counting today. */
    private function weekday(Carbon $now, string $name): Carbon
    {
        $target = self::DAYS[strtolower($name)];

        return $now->dayOfWeekIso === $target ? $now->copy() : $now->copy()->next($target % 7);
    }

    /** A date with no year means the next time it comes round. */
    private function dayMonth(Carbon $now, int $day, string $month): ?Carbon
    {
        $number = self::MONTHS[strtolower($month)];
        if (! checkdate($number, $day, (int) $now->year)) {
            return null;
        }

        $date = Carbon::create($now->year, $number, $day);

        return $date->lt($now) ? $date->addYear() : $date;
    }

    private function tidy(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', trim($text));
        $text = ltrim($text, ' :;,.-');
        $text = (string) preg_replace('/(?:\s+(?:and|for|by|to|on|due))+$/i', '', $text);
        $text = rtrim($text, ' ,;.');

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
