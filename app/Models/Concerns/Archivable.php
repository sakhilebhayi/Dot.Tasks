<?php

namespace App\Models\Concerns;

use App\Models\Scopes\NotArchivedScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reversible hiding via an `archived_at` timestamp. Unlike soft deletion this
 * is a normal state a record can come back from, so it has its own scope and
 * its own verbs.
 */
trait Archivable
{
    public static function bootArchivable(): void
    {
        static::addGlobalScope(new NotArchivedScope);
    }

    public function initializeArchivable(): void
    {
        $this->mergeCasts(['archived_at' => 'datetime']);
    }

    /** @param  Builder<static>  $query */
    public function scopeWithArchived(Builder $query): void
    {
        $query->withoutGlobalScope(NotArchivedScope::class);
    }

    /** @param  Builder<static>  $query */
    public function scopeOnlyArchived(Builder $query): void
    {
        $query->withoutGlobalScope(NotArchivedScope::class)
            ->whereNotNull($this->qualifyColumn('archived_at'));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** Archive this record, optionally at a given instant (so a cascade shares one timestamp). */
    public function archive(?CarbonInterface $at = null): void
    {
        $this->forceFill(['archived_at' => $at ?? now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }
}
