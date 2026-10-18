<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Archived records are hidden unless a query asks for them with
 * withArchived() or onlyArchived(). A global scope rather than a filter each
 * query remembers to add: forgetting one would show archived work as live.
 */
class NotArchivedScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('archived_at'));
    }
}
