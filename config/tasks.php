<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Removed-task retention
    |--------------------------------------------------------------------------
    | Deleting a task is a soft delete: an owner or admin can bring it back from
    | the Removed page. The daily `tasks:purge-removed` command deletes tasks for
    | good once they have been deleted for longer than this many days. Must be at
    | least 1.
    */

    'removed_retention_days' => (int) env('REMOVED_RETENTION_DAYS', 90),

    /* How long the audit log keeps an entry. */
    'audit_months' => (int) env('TASKS_AUDIT_MONTHS', 24),

    /*
    | The hours of estimated work due in 7 days before the Team page flags a person. There is no calendar of who is
    | available, so this is one number for everyone.
    */

    'weekly_hours' => (int) env('TASKS_WEEKLY_HOURS', 40),

];
