{{-- One root element. @unless as the first line prints an HTML comment on the same
     line as <form>, and Livewire then binds only the heading, so Continue reloads the page. --}}
<div>
    @unless ($allowed)
        <div class="flex flex-col gap-3">
            <h2 class="text-base font-medium">Open the setup link from the installer</h2>
            <p class="text-muted">To keep anyone else from creating the superadmin, setup needs the link the installer printed at the end, like <code>https://your-panel/setup?token=...</code>. It is also in <code>/opt/laravelify/.env</code> as <code>LARAVELIFY_SETUP_TOKEN</code>.</p>
        </div>
    @else
        <form class="flex flex-col gap-4" wire:submit="save">
            <div>
                <h2 class="text-base font-medium">Create the superadmin</h2>
                <p class="mt-1 text-muted">This panel has one account. You will set up an authenticator app next.</p>
            </div>

            <label class="field">
                <span>Name</span>
                <input class="input" type="text" wire:model="name" autocomplete="name" required>
                @error('name') <small class="error">{{ $message }}</small> @enderror
            </label>

            <label class="field">
                <span>Email</span>
                <input class="input" type="email" wire:model="email" autocomplete="username" required>
                @error('email') <small class="error">{{ $message }}</small> @enderror
            </label>

            <label class="field">
                <span>Password</span>
                <input class="input" type="password" wire:model="password" autocomplete="new-password" required>
                @error('password') <small class="error">{{ $message }}</small> @enderror
            </label>

            <label class="field">
                <span>Confirm password</span>
                <input class="input" type="password" wire:model="password_confirmation" autocomplete="new-password" required>
            </label>

            <button class="btn btn-primary" type="submit">Continue</button>
        </form>
    @endunless
</div>
