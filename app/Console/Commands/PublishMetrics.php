<?php

namespace App\Console\Commands;

use App\Jobs\SendAnalyticsSnapshot;
use App\Models\Team;
use App\Services\Analytics\AnalyticsClient;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Throwable;

/**
 * Publishes each company's daily aggregate numbers to Dot.Analytics: one queued SendAnalyticsSnapshot per linked,
 * non-personal company. Scheduled daily at a quiet hour (routes/console.php); safe to re-run, because Analytics
 * treats a second snapshot for the same company, platform and UTC day as the same one.
 *
 * Every company is computed with explicit team scoping (MetricsCollector), so the numbers never depend on who
 * is signed in, and one company failing to compute never stops the others. With no ANALYTICS_INGEST_URL or
 * ANALYTICS_INGEST_SECRET nothing is queued; --dry-run prints what would be sent and works without them.
 */
class PublishMetrics extends Command
{
    protected $signature = 'tasks:publish-metrics
        {--company= : Publish only this company (its InfoDot team uuid)}
        {--dry-run : Print each payload instead of queueing it; sends nothing}';

    protected $description = 'Publish each company\'s daily aggregate Dot.Tasks metrics to Dot.Analytics.';

    public function handle(AnalyticsClient $client, MetricsCollector $collector, Dispatcher $bus): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $client->enabled()) {
            $this->info('Dot.Analytics publishing is disabled (ANALYTICS_INGEST_URL or ANALYTICS_INGEST_SECRET is blank); nothing sent.');

            return self::SUCCESS;
        }

        $now = now()->utc();
        $company = trim((string) $this->option('company'));
        $queued = 0;
        $failed = 0;

        $teams = Team::query()
            ->where('personal_team', false)
            ->whereNotNull('infodot_team_uuid')
            ->where('infodot_team_uuid', '!=', '')
            ->when($company !== '', fn ($query) => $query->where('infodot_team_uuid', $company))
            ->lazyById();

        foreach ($teams as $team) {
            try {
                $payload = $client->payload($team, $collector->collect($team, $now), $now);

                if ($payload === null) {
                    continue;
                }

                if ($dryRun) {
                    $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                } else {
                    $bus->dispatch(new SendAnalyticsSnapshot($payload));
                }

                $queued++;
            } catch (Throwable $e) {
                $failed++;
                $this->error('Could not prepare a snapshot for company '.$team->infodot_team_uuid.': '.class_basename($e));
            }
        }

        $this->info(($dryRun ? 'Dry run: ' : '').$queued.' snapshot(s) '.($dryRun ? 'printed' : 'queued').($failed > 0 ? ", {$failed} company(ies) failed" : '').'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
