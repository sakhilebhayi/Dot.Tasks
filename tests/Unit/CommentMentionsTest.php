<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\CommentMentions;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Pure text matching: no database, users are plain unsaved models.
 */
class CommentMentionsTest extends TestCase
{
    /** @param  array<int, string>  $names  id => name */
    private function mentions(array $names): CommentMentions
    {
        $users = new Collection;
        foreach ($names as $id => $name) {
            $user = new User(['name' => $name]);
            $user->id = $id;
            $users->push($user);
        }

        return new CommentMentions($users);
    }

    /** @return list<int> */
    private function ids(CommentMentions $m, string $body): array
    {
        return $m->mentioned($body)->pluck('id')->sort()->values()->all();
    }

    public function test_it_finds_a_mentioned_member_by_full_name_ignoring_case(): void
    {
        $m = $this->mentions([1 => 'Ada Lovelace', 2 => 'Grace Hopper']);

        $this->assertSame([1], $this->ids($m, 'Please look, @ada lovelace'));
        $this->assertSame([1, 2], $this->ids($m, '@Grace Hopper and @Ada Lovelace agree'));
    }

    public function test_the_name_must_be_a_whole_word_and_start_at_a_boundary(): void
    {
        $m = $this->mentions([1 => 'Ada Lovelace']);

        $this->assertSame([], $this->ids($m, 'ping @Ada Lovelaces'), 'a longer word is not a mention');
        $this->assertSame([], $this->ids($m, 'mail me at someone@Ada Lovelace'), 'an email-like @ is not a mention');
        $this->assertSame([], $this->ids($m, 'Ada Lovelace without the at sign'));
        $this->assertSame([1], $this->ids($m, 'Thanks @Ada Lovelace.'), 'trailing punctuation is fine');
    }

    public function test_the_longest_name_wins_over_a_name_that_is_its_prefix(): void
    {
        $m = $this->mentions([1 => 'Sam', 2 => 'Sam Lee']);

        $this->assertSame([2], $this->ids($m, '@Sam Lee please review'));
        $this->assertSame([1], $this->ids($m, '@Sam please review'));
        $this->assertSame([1, 2], $this->ids($m, '@Sam Lee and @Sam'));
    }

    public function test_two_members_with_the_same_name_are_both_mentioned_once(): void
    {
        $m = $this->mentions([1 => 'Pat Kim', 2 => 'Pat Kim']);

        $this->assertSame([1, 2], $this->ids($m, '@Pat Kim @Pat Kim'));
    }

    public function test_regex_characters_in_a_name_are_matched_literally(): void
    {
        $m = $this->mentions([1 => 'A.B. (Bo) Chen+']);

        $this->assertSame([1], $this->ids($m, 'cc @A.B. (Bo) Chen+'));
        $this->assertSame([], $this->ids($m, 'cc @AxBx (Bo) Chen+'));
    }

    public function test_a_member_with_an_empty_name_is_never_matched(): void
    {
        $m = $this->mentions([1 => '', 2 => 'Real Person']);

        $this->assertSame([2], $this->ids($m, '@ and @Real Person'));
    }

    public function test_render_escapes_everything_and_highlights_only_real_mentions(): void
    {
        $m = $this->mentions([1 => 'Ada Lovelace']);

        $html = (string) $m->render('<script>alert(1)</script> hi @Ada Lovelace & @Nobody Here');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertSame(1, substr_count($html, '<span'));
        $this->assertStringContainsString('@Ada Lovelace</span>', $html);
        $this->assertStringContainsString('@Nobody Here', $html);
    }

    public function test_render_escapes_a_member_name_that_contains_html(): void
    {
        $m = $this->mentions([1 => 'Eve <i>Em</i>']);

        $html = (string) $m->render('hello @Eve <i>Em</i>!');

        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringContainsString('@Eve &lt;i&gt;Em&lt;/i&gt;</span>', $html);
    }
}
