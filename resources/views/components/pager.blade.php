{{-- Previous / Next under a paginated list. $rows is a LengthAwarePaginator, $name its page name. --}}
@props(['rows', 'name' => 'page', 'noun' => 'items'])

@if ($rows->hasPages())
    <div {{ $attributes->merge(['class' => 'flex items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-[12.5px] text-muted']) }}>
        <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }} · {{ number_format($rows->total()) }} {{ $noun }}</span>
        <div class="flex gap-1.5">
            <button class="btn" type="button" wire:click="previousPage('{{ $name }}')" @disabled($rows->onFirstPage()) wire:loading.attr="disabled">Previous</button>
            <button class="btn" type="button" wire:click="nextPage('{{ $name }}')" @disabled(! $rows->hasMorePages()) wire:loading.attr="disabled">Next</button>
        </div>
    </div>
@endif
