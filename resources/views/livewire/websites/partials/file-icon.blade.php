<svg class="fm-icon fm-kind-{{ $kind }}" viewBox="0 0 48 40" fill="none" aria-hidden="true">
    @if ($kind === 'folder')
        <path fill="currentColor" d="M4 9a2 2 0 0 1 2-2h11l4 4h21a2 2 0 0 1 2 2v20a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z" opacity=".22"/>
        <path stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M4 9a2 2 0 0 1 2-2h11l4 4h21a2 2 0 0 1 2 2v20a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/>
        <path stroke="currentColor" stroke-width="2" d="M4 15h40" opacity=".5"/>
    @else
        <path fill="currentColor" d="M11 4h17l10 10v22H11z" opacity=".16"/>
        <path stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M11 4h17l10 10v22H11zM28 4v10h10"/>
        @if ($kind === 'image')
            <circle cx="20" cy="20" r="2" fill="currentColor"/>
            <path stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M15 31l7-7 4 4 3-3 5 6"/>
        @elseif ($kind === 'code')
            <path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M21 21l-3 3.5 3 3.5M28 21l3 3.5-3 3.5"/>
        @elseif ($kind === 'archive')
            <path stroke="currentColor" stroke-width="2" d="M23 15h3M23 19h3M23 23h3M22 27h5v4h-5z"/>
        @elseif ($kind === 'text')
            <path stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M17 20h14M17 25h14M17 30h9"/>
        @endif
    @endif
</svg>
