<?php

namespace App\Services;

use App\Models\User;

/** What each person wants to hear about. Everyone starts with the things addressed to them, and adds the rest. */
class NotificationPreferences
{
    public const EVENTS = [
        'assigned' => 'A task is given to me',
        'mentioned' => 'Someone mentions me',
        'comment' => 'A new comment on a task I am part of',
        'due_soon' => 'A task of mine is due soon',
        'due_changed' => 'A due date changes on a task I am part of',
        'status' => 'A task I am part of moves',
        'completed' => 'A task I am part of is finished',
        'unblocked' => 'A task of mine is no longer held up',
        'recurring' => 'A repeating task of mine comes round again',
        'approval' => 'An approval is asked of me, or answered',
        'automation' => 'A rule needs my attention',
    ];

    /** On unless turned off. The rest wait to be turned on, so a busy board stays quiet. */
    private const ON_BY_DEFAULT = ['assigned', 'mentioned', 'comment', 'due_soon', 'unblocked', 'approval', 'automation'];

    /** Dot.Notify carries these by email. The others are in-app only. */
    public const EMAILED = ['assigned', 'mentioned', 'due_soon'];

    public function wants(User $user, string $event, string $channel = 'app'): bool
    {
        $setting = $user->notification_settings[$event][$channel] ?? null;

        return $setting ?? ($channel === 'email' ? in_array($event, self::EMAILED, true) : in_array($event, self::ON_BY_DEFAULT, true));
    }

    public function set(User $user, string $event, string $channel, bool $on): void
    {
        abort_unless(array_key_exists($event, self::EVENTS), 422);
        abort_unless($channel === 'app' || ($channel === 'email' && in_array($event, self::EMAILED, true)), 422);

        $settings = $user->notification_settings ?? [];
        $settings[$event][$channel] = $on;
        $user->update(['notification_settings' => $settings]);
    }
}
