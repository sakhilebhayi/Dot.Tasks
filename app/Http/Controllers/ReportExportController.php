<?php

namespace App\Http\Controllers;

use App\Livewire\Reports;
use App\Services\AuditLog;
use App\Services\TaskReports;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportController extends Controller
{
    public function __invoke(Request $request, TaskReports $reports): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_unless($team && $request->user()->hasTeamPermission($team, 'update'), 403);

        [$from, $to] = Reports::rangeFor((string) $request->query('period', '30'), (string) $request->query('from', ''), (string) $request->query('to', ''));
        $board = $team->boards()->whereKey((int) $request->query('board'))->value('id');

        AuditLog::record($team, 'exported', 'Exported the task report for '.$from->toDateString().' to '.$to->toDateString());

        $rows = $reports->rows($reports->report($team, $from, $to, $board), $from, $to);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'tasks-report-'.$from->toDateString().'-to-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }
}
