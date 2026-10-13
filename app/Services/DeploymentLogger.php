<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Setting;

class DeploymentLogger
{
    /** @var list<string> */
    private array $secrets;

    public function __construct(private Deployment $deployment)
    {
        $website = $deployment->website;
        $this->secrets = array_values(array_filter([
            Setting::getValue('github_token'),
            $website?->db_password,
            $website?->tunnel?->token,
        ]));
    }

    public function line(string $line): void
    {
        $line = $this->readable($line);

        $current = Deployment::query()->whereKey($this->deployment->id)->value('log') ?? '';
        $next = trim($current."\n".$line);

        Deployment::query()->whereKey($this->deployment->id)->update(['log' => $next]);
        $this->deployment->log = $next;
    }

    private function readable(string $line): string
    {
        $line = preg_replace("/\e\[[0-9;?]*[A-Za-z]/", '', $line) ?? $line;
        $line = str_replace("\r", "\n", $line);
        $line = preg_replace('/x-access-token:[^@\s]+/', 'x-access-token:***', $line) ?? $line;
        $line = preg_replace('/\.{8,}/', ' ... ', $line) ?? $line;
        $line = preg_replace('/[ \t]{2,}/', ' ', $line) ?? $line;

        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $line = str_replace($secret, '***', $line);
            }
        }

        return trim($line);
    }
}
