<form class="card flex flex-col gap-4 p-4" wire:submit="save">
    <label class="field">
        <span>Name</span>
        <input class="input" wire:model="name" required>
        @error('name') <small class="error">{{ $message }}</small> @enderror
    </label>
    <label class="field">
        <span>Description</span>
        <textarea class="textarea" style="min-height: 96px" wire:model="description"></textarea>
        @error('description') <small class="error">{{ $message }}</small> @enderror
    </label>
    <div class="flex gap-2">
        <button class="btn btn-primary" type="submit">{{ $group ? 'Save group' : 'Create group' }}</button>
        <a class="btn" href="{{ $group ? route('groups.show', $group) : route('groups.index') }}" wire:navigate>Cancel</a>
    </div>
</form>
