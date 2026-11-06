<?php

namespace App\Livewire\Concerns;

/** A long column or group draws its first cards and the rest on request, so a large board stays quick to open. */
trait ShowsInBatches
{
    public int $batch = 100;

    public function showMore(): void
    {
        $this->batch += 200;
    }
}
