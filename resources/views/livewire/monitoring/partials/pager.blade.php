{{-- Previous / next page of a GlitchTip list (cursor pages). --}}
@if ($page['items'] !== [] && ($cursors !== [] || $page['next']))
    <div class="gt-pager">
        <span class="text-[12.5px] text-muted">
            Page {{ count($cursors) + 1 }}@if ($page['total'] !== null) · {{ number_format($page['total']) }} {{ str($noun)->plural($page['total']) }}@endif
        </span>
        <div class="flex gap-1.5">
            <button class="btn btn-sm" type="button" wire:click="previousPage" @disabled($cursors === [])><x-menu-icon name="chevron-right" class="size-4 rotate-180" /> Previous</button>
            <button class="btn btn-sm" type="button" @if ($page['next']) wire:click="nextPage(@js($page['next']))" @else disabled @endif>Next <x-menu-icon name="chevron-right" class="size-4" /></button>
        </div>
    </div>
@endif
