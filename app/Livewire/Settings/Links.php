<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\Setting;
use App\Support\SidebarLinks;

class Links extends PanelComponent
{
    /** @var list<array{label: string, url: string}> */
    public array $links = [];

    public function mount(): void
    {
        $this->links = SidebarLinks::custom();
    }

    public function add(): void
    {
        if (count($this->links) < 15) {
            $this->links[] = ['label' => '', 'url' => 'https://'];
        }
    }

    public function remove(int $index): void
    {
        unset($this->links[$index]);
        $this->links = array_values($this->links);
    }

    public function move(int $index, int $step): void
    {
        $target = $index + $step;

        if (isset($this->links[$index], $this->links[$target])) {
            [$this->links[$index], $this->links[$target]] = [$this->links[$target], $this->links[$index]];
        }
    }

    public function save(): void
    {
        $this->links = array_values(array_filter($this->links, fn (array $link): bool => trim($link['label'] ?? '') !== '' || trim($link['url'] ?? '') !== 'https://' && trim($link['url'] ?? '') !== ''));

        $this->validate([
            'links' => ['array', 'max:15'],
            'links.*.label' => ['required', 'string', 'max:40'],
            'links.*.url' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! SidebarLinks::validUrl($value)) {
                    $fail('Use a full http:// or https:// address.');
                }
            }],
        ], [], ['links.*.label' => 'label', 'links.*.url' => 'address']);

        Setting::putValue('sidebar_links', json_encode(array_map(fn (array $link): array => [
            'label' => trim($link['label']),
            'url' => trim($link['url']),
        ], $this->links)));

        $this->toast('Links saved. They appear in the sidebar.');
    }

    public function render()
    {
        return view('livewire.settings.links', [
            'phpMyAdmin' => SidebarLinks::phpMyAdminAvailable(),
        ])->layout('layouts.app')->title('Links');
    }
}
