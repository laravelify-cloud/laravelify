{{-- Where the GitHub App is made: an organization (picked from the known ones, or typed) or the personal account. --}}
@php
    $knownOrganizations = \App\Services\GithubAppClient::knownOrganizations();
    $hasToken = filled(\App\Models\Setting::getValue('github_token'));
@endphp

<div class="field sm:col-span-2" x-data>
    <span>Organization</span>
    <div class="flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm" :class="$wire.organization === '' && 'btn-primary'" @click="$wire.set('organization', '')">
            Personal account
        </button>
        @foreach ($knownOrganizations as $knownOrganization)
            <button type="button" class="btn btn-sm" :class="$wire.organization === @js($knownOrganization) && 'btn-primary'" @click="$wire.set('organization', @js($knownOrganization))">
                <x-menu-icon name="groups" class="size-4" /> {{ $knownOrganization }}
            </button>
        @endforeach
    </div>
    <input class="input" wire:model.live.debounce.400ms="organization" autocomplete="off" placeholder="Or type an organization name">
    <small class="text-muted">
        The App is made in this account and can only be installed there. Empty is your personal account.
        @unless ($hasToken)
            To see all your organizations here, save a GitHub token in <a class="underline" href="{{ route('settings.github') }}" wire:navigate>Settings → GitHub</a>; until then only the organizations of your other Apps show.
        @endunless
    </small>
    @error('organization') <small class="error">{{ $message }}</small> @enderror
</div>
