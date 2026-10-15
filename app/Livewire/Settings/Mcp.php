<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\McpToken;
use App\Services\TwoFactor;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * Settings → MCP: tokens for AI clients (Claude Code, Cursor, …) and how to connect them.
 */
class Mcp extends PanelComponent
{
    public string $tokenName = '';

    /** The token just made, shown once. */
    #[Locked]
    public string $newToken = '';

    /**
     * Check the form. The page then asks for the authenticator code.
     */
    public function check(): bool
    {
        $this->validate(['tokenName' => ['required', 'string', 'max:80']], [], ['tokenName' => 'name']);

        return true;
    }

    public function create(TwoFactor $twoFactor, string $code): bool
    {
        $this->check();
        $this->resetErrorBag('code');

        try {
            $twoFactor->confirmCode(auth()->user(), $code, 'mcp-token');
        } catch (Throwable $exception) {
            $this->addError('code', $exception->getMessage());

            return false;
        }

        $this->newToken = McpToken::issue(auth()->user(), trim($this->tokenName))['plain'];
        $this->tokenName = '';
        $this->toast('Token created. Copy it now: it is shown only once.');

        return true;
    }

    public function dismissToken(): void
    {
        $this->newToken = '';
    }

    public function revoke(int $id): void
    {
        McpToken::query()->whereKey($id)->delete();
        $this->toast('Token revoked. Clients using it can no longer connect.');
    }

    public function render()
    {
        return view('livewire.settings.mcp', [
            'tokens' => McpToken::query()->latest('id')->get(),
            'endpoint' => route('mcp'),
        ])->layout('layouts.app')->title('MCP');
    }
}
