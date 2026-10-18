<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * @Name mentions in a comment, matched against the members of one team and
 * nobody else, so a mention can never reach (or highlight) a stranger.
 *
 * A mention is "@" followed by a member's full name, case-insensitive, that
 * starts at a word boundary (so "me@Ada Lovelace" is an email, not a mention)
 * and ends at one (so "@Ada Lovelaces" is not "@Ada Lovelace"). The longest
 * name is tried first and its text is used up, so "@Sam Lee" mentions Sam Lee
 * and not also Sam.
 *
 * Comments are plain text. render() escapes all of it; the only markup it
 * adds is the highlight around a real mention.
 */
class CommentMentions
{
    /** @param  Collection<int, User>  $members */
    public function __construct(private readonly Collection $members) {}

    public static function forTeam(Team $team): self
    {
        return new self($team->allUsers());
    }

    /**
     * The team members this text mentions, each once.
     *
     * @return Collection<int, User>
     */
    public function mentioned(string $body): Collection
    {
        return collect($this->spans($body))
            ->flatMap(fn (array $span) => $span['users'])
            ->unique('id')
            ->values();
    }

    /** The comment as safe HTML: escaped text, with real mentions highlighted. */
    public function render(string $body): HtmlString
    {
        $html = '';
        $cursor = 0;

        foreach ($this->spans($body) as $span) {
            $html .= e(substr($body, $cursor, $span['start'] - $cursor));
            $html .= '<span class="font-semibold text-indigo-600 dark:text-indigo-300">'.e(substr($body, $span['start'], $span['length'])).'</span>';
            $cursor = $span['start'] + $span['length'];
        }

        return new HtmlString($html.e(substr($body, $cursor)));
    }

    /**
     * Where each mention sits in the text (byte offsets), in order.
     *
     * @return list<array{start: int, length: int, users: Collection<int, User>}>
     */
    private function spans(string $body): array
    {
        $byName = $this->members
            ->filter(fn (User $user) => trim((string) $user->name) !== '')
            ->groupBy(fn (User $user) => mb_strtolower(trim($user->name)));

        // Longest first, so a name that is the start of another loses to it.
        $names = $byName->keys()->sortByDesc(fn (string $name) => mb_strlen($name))->values();

        $spans = [];
        $unclaimed = $body;

        foreach ($names as $name) {
            $pattern = '/(?<![\p{L}\p{N}_@])@'.preg_quote((string) $name, '/').'(?![\p{L}\p{N}_])/iu';

            if (! preg_match_all($pattern, $unclaimed, $found, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($found[0] as [$text, $offset]) {
                $spans[] = ['start' => $offset, 'length' => strlen($text), 'users' => $byName[$name]];

                // Use the text up so a shorter name inside it cannot match again.
                $unclaimed = substr_replace($unclaimed, str_repeat("\0", strlen($text)), $offset, strlen($text));
            }
        }

        usort($spans, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $spans;
    }
}
