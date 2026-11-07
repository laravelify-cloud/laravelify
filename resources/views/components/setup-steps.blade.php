@props(['current' => 1])

{{-- First-run progress shown above the setup forms: the account, then the authenticator app. --}}
@php($steps = [1 => 'Account', 2 => 'Two-factor'])

<ol {{ $attributes->class(['guest-steps']) }} aria-label="Setup progress">
    @foreach ($steps as $number => $label)
        <li @class(['is-done' => $number < $current, 'is-current' => $number === $current]) @if ($number === $current) aria-current="step" @endif>
            <span class="guest-step-dot">
                @if ($number < $current)
                    <x-menu-icon name="check" class="size-3.5" />
                @else
                    {{ $number }}
                @endif
            </span>
            <span class="guest-step-label">{{ $label }}</span>
        </li>
    @endforeach
</ol>
