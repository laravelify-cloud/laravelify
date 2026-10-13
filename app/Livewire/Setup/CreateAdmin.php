<?php

namespace App\Livewire\Setup;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class CreateAdmin extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        if (User::query()->exists()) {
            $this->redirect(route('login'));
        }
    }

    public function save(): void
    {
        if (User::query()->exists()) {
            $this->redirect(route('login'));

            return;
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        Auth::login($user);
        session()->regenerate();

        $this->redirect(route('setup.two-factor'));
    }

    public function render()
    {
        return view('livewire.setup.create-admin')
            ->layout('layouts.guest')
            ->title('Create the superadmin');
    }
}
