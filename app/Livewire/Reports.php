<?php

namespace App\Livewire;

use App\Models\TaskField;
use App\Models\Team;
use App\Services\TaskReports;
use App\Support\Today;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Reports for people who run the work: admins, the owner and editors. */
class Reports extends Component
{
    public const PERIODS = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year', 'custom' => 'Chosen dates'];

    #[Url(except: '30')]
    public string $period = '30';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $board = '';

    /** A custom field to split open work by, such as a department. */
    #[Url(except: '')]
    public string $field = '';

    private function team(): Team
    {
        return auth()->user()->currentTeam;
    }

    public function mount(): void
    {
        abort_unless($this->team() && auth()->user()->hasTeamPermission($this->team(), 'update'), 403);
    }

    #[Computed]
    public function boards()
    {
        return $this->team()->boards()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function fields()
    {
        return TaskField::where('team_id', $this->team()->id)->orderBy('sort_order')->get(['id', 'name']);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    #[Computed]
    public function range(): array
    {
        return self::rangeFor($this->period, $this->from, $this->to);
    }

    /** @return array{0: Carbon, 1: Carbon} a period, or chosen dates if they make sense, or the last 30 days */
    public static function rangeFor(string $period, string $from, string $to): array
    {
        $today = Today::date();
        $date = fn (string $value) => Carbon::hasFormatWithModifiers($value, 'Y#m#d') ? Carbon::createFromFormat('!Y-m-d', $value) : null;

        if ($period === 'custom' && ($start = $date($from)) && ($end = $date($to)) && $start->lte($end)) {
            return [$start, $end];
        }

        return [$today->copy()->subDays(ctype_digit($period) ? max(1, min(3660, (int) $period)) : 30), $today];
    }

    #[Computed]
    public function report(): array
    {
        [$from, $to] = $this->range;

        return app(TaskReports::class)->report(
            $this->team(), $from, $to,
            ctype_digit($this->board) && $this->boards->contains('id', (int) $this->board) ? (int) $this->board : null,
            ctype_digit($this->field) && $this->fields->contains('id', (int) $this->field) ? (int) $this->field : null,
        );
    }

    public function render(): View
    {
        return view('livewire.reports');
    }
}
