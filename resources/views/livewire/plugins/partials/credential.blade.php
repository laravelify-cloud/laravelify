{{-- One credential in the shown-once modal: hidden until revealed (one or all), with a copy button. Copying counts as saved. --}}
<div class="credential" x-data="{ reveal: false }">
    <dt>{{ $label }}</dt>
    <dd @class(['copy-field', 'has-reveal' => $secret])>
        <input class="input font-mono" value="{{ $value }}" readonly
            @if ($secret) :type="reveal || revealAll ? 'text' : 'password'" type="password" @endif
            autocomplete="off" spellcheck="false" aria-label="{{ $label }}">
        @if ($secret)
            <button type="button" class="icon-btn reveal-button" @click="reveal = ! reveal" :title="reveal || revealAll ? 'Hide' : 'Show'" :aria-label="(reveal || revealAll ? 'Hide ' : 'Show ') + @js($label)">
                <x-menu-icon name="eye" class="size-4" x-show="! (reveal || revealAll)" />
                <x-menu-icon name="eye-off" class="size-4" x-show="reveal || revealAll" x-cloak />
            </button>
        @endif
        <x-copy-button :text="\Illuminate\Support\Js::from($value)->toHtml()" @click="saved = true" />
    </dd>
</div>
