<?php

namespace App\Livewire;

use App\Services\NotificationPreferences;
use Illuminate\View\View;
use Livewire\Component;

/** A person's own say in what reaches them. */
class NotificationSettings extends Component
{
    public function toggle(string $event, string $channel): void
    {
        $prefs = app(NotificationPreferences::class);

        $prefs->set(auth()->user(), $event, $channel, ! $prefs->wants(auth()->user(), $event, $channel));
    }

    public function render(): View
    {
        return view('livewire.notification-settings', ['prefs' => app(NotificationPreferences::class)]);
    }
}
