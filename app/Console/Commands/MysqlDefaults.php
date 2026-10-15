<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * MySQL settings every Laravelify server needs, applied with SET PERSIST so they take effect at
 * once and survive restarts. Run by the installer and by every update.
 */
class MysqlDefaults extends Command
{
    protected $signature = 'laravelify:mysql-defaults';

    protected $description = 'Apply the MySQL settings Laravelify sites need (triggers with binary logging on)';

    /**
     * MySQL 8 keeps a binary log, and then only SUPER may create triggers, functions, and
     * procedures. Site users do not have SUPER, so Laravel migrations with triggers failed with
     * error 1419. This one setting lets their own users create them.
     *
     * @var array<string, string>
     */
    public const SETTINGS = [
        'log_bin_trust_function_creators' => '1',
    ];

    public function handle(): int
    {
        try {
            foreach (self::SETTINGS as $name => $value) {
                DB::connection('mysql')->statement('SET PERSIST '.$name.' = '.$value);
                $this->info('MySQL: '.$name.' = '.$value);
            }
        } catch (Throwable $exception) {
            $this->warn('Could not change MySQL settings: '.$exception->getMessage());
            $this->warn('Run it as the MySQL root user: SET PERSIST log_bin_trust_function_creators = 1;');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
