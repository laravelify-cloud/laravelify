<?php

namespace App\Livewire\Security;

use App\Livewire\PanelComponent;
use App\Models\PrivateKey;
use Illuminate\Validation\Rule;
use Throwable;

class PrivateKeys extends PanelComponent
{
    public bool $showCreate = false;

    public bool $editorOpen = false;

    public string $newName = '';

    public string $newDescription = '';

    public string $newPrivateKey = '';

    public ?int $editingId = null;

    public string $editName = '';

    public string $editDescription = '';

    public string $editPrivateKey = '';

    public string $editPublicKey = '';

    public string $notice = '';

    public bool $noticeOk = true;

    public function generate(string $type): void
    {
        if (! in_array($type, ['rsa', 'ed25519'], true)) {
            $this->fail('Choose RSA or ED25519.');

            return;
        }

        try {
            $pair = PrivateKey::generate($type);
            PrivateKey::query()->create([
                'name' => $this->randomName(),
                'description' => 'Generated '.strtoupper($type).' key',
                'private_key' => $pair['private'],
                'is_git_related' => false,
            ]);
            $this->ok('Private key generated.');
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
    }

    public function createManual(): void
    {
        $validated = $this->validate([
            'newName' => ['required', 'string', 'max:120', 'unique:private_keys,name'],
            'newDescription' => ['nullable', 'string', 'max:255'],
            'newPrivateKey' => ['required', 'string', 'max:20000'],
        ]);

        PrivateKey::query()->create([
            'name' => $validated['newName'],
            'description' => $validated['newDescription'] !== '' ? $validated['newDescription'] : null,
            'private_key' => PrivateKey::normalize($validated['newPrivateKey']),
            'is_git_related' => false,
        ]);

        $this->reset('newName', 'newDescription', 'newPrivateKey', 'showCreate');
        $this->ok('Private key saved.');
    }

    public function openEditor(int $id): void
    {
        $key = PrivateKey::query()->findOrFail($id);
        $this->editingId = $key->id;
        $this->editName = $key->name;
        $this->editDescription = (string) $key->description;
        $this->editPrivateKey = (string) $key->private_key;
        $this->editPublicKey = (string) $key->public_key;
        $this->confirmName = '';
        $this->editorOpen = true;
    }

    public function closeEditor(): void
    {
        $this->editorOpen = false;
        $this->editingId = null;
    }

    public function saveEditor(): void
    {
        $key = PrivateKey::query()->findOrFail($this->editingId);
        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:120', Rule::unique('private_keys', 'name')->ignore($key->id)],
            'editDescription' => ['nullable', 'string', 'max:255'],
            'editPrivateKey' => ['required', 'string', 'max:20000'],
        ]);

        $key->fill([
            'name' => $validated['editName'],
            'description' => $validated['editDescription'] !== '' ? $validated['editDescription'] : null,
            'private_key' => PrivateKey::normalize($validated['editPrivateKey']),
        ])->save();

        $this->closeEditor();
        $this->ok('Private key saved.');
    }

    public function deleteKey(): void
    {
        $key = PrivateKey::query()->findOrFail($this->editingId);

        if (! $this->confirmed($key->name)) {
            return;
        }

        if ($key->isInUse()) {
            $this->fail('This private key is used by a GitHub App. Choose another key there before deleting it.');

            return;
        }

        $key->delete();
        $this->closeEditor();
        $this->ok('Private key deleted.');
    }

    public function deleteUnused(): void
    {
        if (! $this->confirmed('unused')) {
            return;
        }

        $deleted = 0;

        PrivateKey::query()->orderBy('id')->each(function (PrivateKey $key) use (&$deleted): void {
            if (! $key->isInUse()) {
                $key->delete();
                $deleted++;
            }
        });

        $this->ok($deleted === 0 ? 'No unused keys to delete.' : 'Unused keys deleted.');
    }

    public function render()
    {
        return view('livewire.security.private-keys', [
            'keys' => PrivateKey::query()->withCount('githubApps')->orderBy('name')->get(),
        ])->layout('layouts.app')->title('Private keys');
    }

    private function randomName(): string
    {
        $adjectives = ['calm', 'lucky', 'gifted', 'brave', 'quiet', 'swift'];
        $nouns = ['lynx', 'gerbil', 'otter', 'falcon', 'maple', 'cedar'];

        return $adjectives[array_rand($adjectives)].'-'.$nouns[array_rand($nouns)].'-'.bin2hex(random_bytes(3));
    }

    private function ok(string $message): void
    {
        $this->notice = $message;
        $this->noticeOk = true;
        $this->toast($message);
    }

    private function fail(string $message): void
    {
        $this->notice = $message;
        $this->noticeOk = false;
        $this->toast($message, 'error');
    }
}
