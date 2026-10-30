<?php

return [
    /*
    | Strict-Transport-Security max-age in seconds, sent on HTTPS responses only. 0 turns it off. Subdomains are deliberately not included
    | (no includeSubDomains, no preload): the other Dot platforms sit on neighbouring subdomains and set their own.
    */
    'hsts_seconds' => (int) env('SECURITY_HSTS_SECONDS', 15552000),   // 180 days

    /*
    | The Content-Security-Policy is sent in REPORT-ONLY mode: the browser reports what the policy would have blocked (to /csp-report) and blocks
    | nothing. Setting this to false would ENFORCE the same policy, which still allows 'unsafe-inline' and 'unsafe-eval'; only do that after the
    | logged reports have been reviewed.
    */
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),
];
