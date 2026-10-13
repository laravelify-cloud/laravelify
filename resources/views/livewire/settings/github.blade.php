<form class="card flex max-w-xl flex-col gap-4 p-4" wire:submit="save">
    <p class="text-muted">
        One token for private GitHub repositories. Laravelify uses it as <code>x-access-token</code> when the site URL is <code>https://github.com/...</code>.
        Push deploys still use each website's webhook secret. This token is stored encrypted.
        Status: {{ $hasToken ? 'a token is saved' : 'no token saved' }}.
    </p>
    <x-secret-input label="Personal access token" model="token" secret="github_token" :saved="$hasToken" :placeholder="$hasToken ? null : 'github_pat_...'" />
    <div class="flex gap-2">
        <button class="btn btn-primary" type="submit">Save token</button>
        @if ($hasToken)
            <x-confirm-delete
                title="Confirm token removal?"
                name="github-token"
                action="clear"
                button="Remove token"
                :actions="['The saved personal access token will be removed.']"
            />
        @endif
    </div>
</form>
