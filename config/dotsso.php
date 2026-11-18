<?php

use App\Sso\TasksProvisioner;

/*
 * Dot.Tasks' own choices for the shared sign-in package (packages/dot-sso-client). Everything else, including
 * DOTSSO_ENABLED (default false), comes from the package's defaults and the host .env.
 */
return [
    'provisioner' => TasksProvisioner::class,

    // Tasks has always created an account for a person InfoDot vouches for (INFODOT_PROVISION_USERS), so that stays.
    'auto_provision' => (bool) env('DOTSSO_AUTO_PROVISION', env('INFODOT_PROVISION_USERS', true)),

    'landing' => '/dashboard',

    // A deep link may open any signed-in page of the app, and nothing else.
    'deep_link' => [
        'allow' => ['#^/(dashboard|my-tasks|inbox|today|due-now|team-work|upcoming|lists|labels|templates|automation|reports|connections|audit|tasks|notifications|removed|escalations|user/profile|teams)(/|\?|$)#'],
        'deny' => ['#^/(sso|login|register|logout|forgot-password|reset-password|auth|oauth|two-factor-challenge|user/confirm)(/|\?|$)#i'],
    ],

    // Never intercepted: nothing extra. Tasks' machine doors (Sanctum, /up, /ready, /guardian) are already outside the
    // pages the package takes over.
    'allow_paths' => [],
];
