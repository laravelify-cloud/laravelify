<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\DomainSuggestions;
use App\Services\GithubAppClient;
use App\Services\PortAllocator;
use App\Services\WebsiteEnvironments;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * The environments of a website (production, development, staging…), shown above its settings:
 * switch between them, or add one that deploys an existing branch or a new one.
 */
class Environments extends PanelComponent
{
    public Website $website;

    public string $environment = 'development';

    /** 'existing' to deploy a branch that is there, 'new' to make one on GitHub first. */
    public string $branchMode = 'new';

    public string $branch = '';

    public string $from = '';

    public string $domain = '';

    public string $httpPort = '';

    /** The "Add environment" dialog is open. */
    public bool $dialog = false;

    /** 'port' or 'domain'. */
    public string $access = 'port';

    /**
     * @return array{branches: list<string>, error: ?string}
     */
    #[Renderless]
    public function branches(GithubAppClient $github): array
    {
        $main = $this->main();
        $fullName = GithubAppClient::fullName((string) $main->repository);

        if ($fullName === null || ! $main->githubApp?->isConnected()) {
            return ['branches' => array_values(array_unique(array_filter([$main->branch, ...$main->environments->pluck('branch')->all()]))), 'error' => 'Branches are listed for GitHub repositories connected through a GitHub App. Type the branch name.'];
        }

        try {
            return ['branches' => $github->branches($main->githubApp, $fullName), 'error' => null];
        } catch (Throwable $exception) {
            return ['branches' => [], 'error' => $exception->getMessage()];
        }
    }

    public function randomPort(PortAllocator $ports): void
    {
        $this->httpPort = (string) $ports->random();
        $this->domain = '';
    }

    /**
     * A free subdomain for the environment, like development-calm-river-4f2a.example.com.
     */
    public function randomDomain(string $zone, DomainSuggestions $domains): void
    {
        if (! in_array($zone, $this->zones($domains), true)) {
            return;
        }

        $name = $domains->random($zone);
        $prefix = preg_replace('/[^a-z0-9-]/', '', strtolower($this->environment));
        $this->domain = $prefix !== '' && strlen($prefix) <= 20 ? $prefix.'-'.$name : $name;
        $this->httpPort = '';
        $this->resetErrorBag('domain');
    }

    /**
     * The Cloudflare zones random subdomains can be made on: the main site's own zone first.
     *
     * @return list<string>
     */
    private function zones(DomainSuggestions $domains): array
    {
        $zones = array_column($domains->options()['zones'], 'name');
        $domain = strtolower((string) $this->main()->domain);
        usort($zones, fn (string $a, string $b): int => (int) str_ends_with($domain, $b) <=> (int) str_ends_with($domain, $a));

        return $zones;
    }

    public function create(WebsiteEnvironments $environments): void
    {
        $this->resetErrorBag();

        try {
            $result = $environments->create($this->main(), [
                'environment' => $this->environment,
                // A new branch is named after the environment unless another name was typed.
                'branch' => $this->branchMode === 'new' && trim($this->branch) === '' ? $this->environment : $this->branch,
                'from' => $this->branchMode === 'new' ? ($this->from !== '' ? $this->from : (string) $this->main()->branch) : null,
                'domain' => $this->domain,
                'http_port' => $this->httpPort,
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError(in_array($field, ['environment', 'branch', 'domain', 'http_port'], true) ? $field : 'environment', $messages[0]);
            }

            return;
        }

        $this->toastAfterRedirect($result['error'] === null
            ? 'The '.$result['website']->environment.' environment is deploying '.$result['website']->branch.'.'
            : 'Environment saved. Applying nginx or the process manager failed: '.$result['error'], $result['error'] === null ? 'success' : 'error');
        $this->redirect(route('websites.show', $result['website']));
    }

    public function render()
    {
        $main = $this->main();

        $domains = app(DomainSuggestions::class);

        return view('livewire.websites.environments', [
            'main' => $main,
            // Read from Cloudflare only while the dialog is open (and cached for two minutes).
            'zones' => $this->dialog ? $this->zones($domains) : [],
            'family' => collect([$main])->merge($main->environments()->with('deployments')->get()),
        ]);
    }

    private function main(): Website
    {
        return $this->website->parent ?? $this->website;
    }
}
