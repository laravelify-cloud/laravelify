<?php

namespace App\Services;

use App\Models\Website;
use App\Support\DatabaseEngines;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Database servers added after setup: PostgreSQL or MongoDB is installed when a website first
 * needs it (host-apply.sh database-install), the panel's login for it is saved in its .env, and
 * the website that asked gets its database right after.
 */
class DatabaseServers
{
    public const INSTALLABLE = [DatabaseEngines::POSTGRESQL, DatabaseEngines::MONGODB];

    public function __construct(private HostWriter $host, private DatabaseProvisioner $provisioner) {}

    /** Whether this engine can be added to this server from the panel. */
    public static function installable(string $engine): bool
    {
        return in_array($engine, self::INSTALLABLE, true) && ! DatabaseEngines::has($engine);
    }

    /**
     * Install the engine and make the panel use it; with a website, create its database too.
     */
    public function install(string $engine, ?Website $website = null): string
    {
        if (! in_array($engine, self::INSTALLABLE, true)) {
            throw new RuntimeException('Only PostgreSQL and MongoDB can be added from the panel.');
        }

        $label = DatabaseEngines::label($engine);

        if (! DatabaseEngines::has($engine)) {
            $login = $this->host->databaseInstall($engine);
            // host-apply.sh saved them in the panel's .env (root owns it); this request uses them now.
            $engines = array_values(array_unique([...(array) config('laravelify.databases'), $engine]));
            config([
                'laravelify.databases' => $engines,
                'laravelify.'.($engine === DatabaseEngines::POSTGRESQL ? 'postgres' : 'mongodb').'_user' => $login['user'],
                'laravelify.'.($engine === DatabaseEngines::POSTGRESQL ? 'postgres' : 'mongodb').'_password' => $login['password'],
            ]);

            // The queue worker started before the PHP extension and the new settings: it restarts
            // after this task (Supervisor starts it again).
            Artisan::call('queue:restart');
        }

        if ($website === null) {
            return $label.' is installed. Websites can use it now.';
        }

        if ($engine === DatabaseEngines::POSTGRESQL && ! extension_loaded('pdo_pgsql')) {
            return $label.' is installed. Press Create '.$label.' on '.$website->name.' again to make its database (the panel restarts to load PostgreSQL support).';
        }

        return $label.' is installed. '.$this->provisioner->provisionFor($website, $engine);
    }
}
