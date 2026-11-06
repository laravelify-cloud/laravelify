<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\File;

/**
 * PHP and Node.js versions on this server. A website can pick any supported version, installed
 * or not: the missing one is installed before the website uses it (deploy, PHP tab).
 */
class Runtimes
{
    public function __construct(private HostWriter $host) {}

    public function phpInstalled(string $version): bool
    {
        return is_file("/usr/sbin/php-fpm{$version}");
    }

    /** A major (19), minor (19.4) or exact version (19.0.0) is there when any match is. */
    public function nodeInstalled(string $version): bool
    {
        $root = '/usr/local/n/versions/node';

        return is_dir("{$root}/{$version}") || (File::glob("{$root}/{$version}.*", GLOB_ONLYDIR) ?: []) !== [];
    }

    /** Python 3.12, Go 1.24, Bun 1.2 or Hugo 0.145, or a newer patch of it, is installed. */
    public function toolchainInstalled(string $toolchain, string $version): bool
    {
        $root = '/opt/laravelify-tools/'.$toolchain;

        if ($toolchain === 'python') {
            return (File::glob("{$root}/cpython-{$version}*", GLOB_ONLYDIR) ?: []) !== [];
        }

        return is_dir("{$root}/{$version}") || (File::glob("{$root}/{$version}.*", GLOB_ONLYDIR) ?: []) !== [];
    }

    /**
     * Install what the website needs and the server lacks. Returns a line for the log, or null
     * when everything was there.
     */
    public function ensure(Website $website): ?string
    {
        $done = [];

        if ($website->isPhp() && $website->php_version && ! $this->phpInstalled($website->php_version)) {
            $this->host->phpInstall($website->php_version);
            $done[] = 'PHP '.$website->php_version;
        }

        if ($website->usesNode() && $website->node_version && ! $this->nodeInstalled($website->node_version)) {
            $this->host->nodeInstall($website->node_version);
            $done[] = 'Node.js '.$website->node_version;
        }

        if ($website->hasToolchain() && ! $this->toolchainInstalled($website->toolchain(), (string) $website->runtimeVersion())) {
            $this->host->toolchainInstall($website->toolchain(), (string) $website->runtimeVersion());
            $done[] = (string) $website->runtimeLabel();
        }

        return $done === [] ? null : 'Installed '.implode(' and ', $done).' on this server for this website.';
    }
}
