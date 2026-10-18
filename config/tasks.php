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

];
