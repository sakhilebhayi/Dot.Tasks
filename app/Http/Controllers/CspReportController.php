<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content-Security-Policy violation reports while the policy is in report-only mode, so a strict policy can be designed from what the
 * pages actually load instead of guessed. It keeps almost nothing: the directive and the blocked HOST (never the full address, which can carry
 * tokens in its query), logged at most once per pair per day. The body is limited and never echoed. The endpoint is public, so every field is
 * treated as untrusted: non-string values are ignored and the kept values are length-capped.
 */
class CspReportController extends Controller
{
    private const MAX_BODY_BYTES = 8192;

    private const MAX_REPORTS = 5;

    public function __invoke(Request $request): Response
    {
        $json = json_decode(substr((string) $request->getContent(), 0, self::MAX_BODY_BYTES), true);

        foreach (array_slice($this->reportsIn($json), 0, self::MAX_REPORTS) as $report) {
            if (! is_array($report)) {
                continue;
            }

            $directive = $this->text($report['effective-directive'] ?? $report['effectiveDirective'] ?? $report['violated-directive'] ?? '');
            $directive = substr((string) preg_replace('/[^a-z-]/', '', strtolower($directive)), 0, 40);
            $host = $this->blockedHost($this->text($report['blocked-uri'] ?? $report['blockedURL'] ?? ''));

            if ($directive !== '' && Cache::add("csp:$directive:$host:".now()->format('Y-m-d'), 1, 90000)) {
                Log::info('csp.violation', ['directive' => $directive, 'blocked_host' => $host]);
            }
        }

        return response()->noContent();
    }

    /**
     * Two shapes exist: {"csp-report": {...}} (report-uri) and [{"type": "csp-violation", "body": {...}}] (Reporting API).
     *
     * @return array<int|string, mixed>
     */
    private function reportsIn(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        if (isset($json['csp-report'])) {
            return [$json['csp-report']];
        }

        if (! array_is_list($json)) {
            return [];
        }

        return array_map(fn ($entry) => is_array($entry) ? ($entry['body'] ?? []) : [], $json);
    }

    /** The host the browser blocked, or a keyword (inline, eval, self, none, other). Never the full address. */
    private function blockedHost(string $blocked): string
    {
        $host = in_array($blocked, ['inline', 'eval', 'self', ''], true)
            ? ($blocked ?: 'none')
            : (strtolower((string) parse_url($blocked, PHP_URL_HOST)) ?: 'other');

        // Capped so the once-a-day cache key stays far inside a database cache table's key column.
        return substr((string) preg_replace('/[^a-z0-9.\-]/', '', $host), 0, 100) ?: 'other';
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
