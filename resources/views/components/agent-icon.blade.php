{{-- An AI agent's mark: Claude's sunburst, Cursor's cube, GitHub's mark for Copilot. --}}
@props(['agent'])

@if ($agent === 'claude')
    <svg {{ $attributes->merge(['class' => 'agent-icon']) }} viewBox="0 0 24 24" aria-hidden="true" style="color: #d97757">
        @foreach ([0 => 10, 30 => 8, 60 => 10.5, 90 => 8.5, 120 => 10, 150 => 8, 180 => 10.5, 210 => 8.5, 240 => 10, 270 => 8, 300 => 10.5, 330 => 8.5] as $angle => $length)
            <rect x="11" y="{{ 12 - $length }}" width="2" height="{{ $length }}" rx="1" fill="currentColor" transform="rotate({{ $angle }} 12 12)" />
        @endforeach
    </svg>
@elseif ($agent === 'cursor')
    <svg {{ $attributes->merge(['class' => 'agent-icon']) }} viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12 2 21 7v10l-9 5-9-5V7z" fill="currentColor" opacity=".35" />
        <path d="M12 2 21 7l-9 5-9-5z" fill="currentColor" opacity=".7" />
        <path d="M12 12 21 7v10l-9 5z" fill="currentColor" />
    </svg>
@else
    <x-menu-icon name="github" {{ $attributes->merge(['class' => 'agent-icon']) }} />
@endif
