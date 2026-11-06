<?php

namespace App\Livewire\Concerns;

/**
 * What a lazily loaded section shows until it has loaded: a few grey bars, so the page opens at
 * once and the section fills in when it is opened.
 */
trait LazyPlaceholder
{
    public function placeholder(): string
    {
        return <<<'HTML'
            <div class="flex flex-col gap-3" aria-busy="true" aria-label="Loading">
                <div class="gt-skeleton" style="height: 56px"></div>
                <div class="gt-skeleton" style="height: 40px"></div>
                <div class="gt-skeleton" style="height: 40px"></div>
                <div class="gt-skeleton" style="height: 40px"></div>
            </div>
            HTML;
    }
}
