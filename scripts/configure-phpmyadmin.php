<?php

/**
 * Give phpMyAdmin a working control user and configuration-storage tables.
 * Reads the MySQL root password from /opt/laravelify/.env. Prints no secrets.
 */
$config = '/etc/phpmyadmin/config-db.php';
$sql = '/usr/share/phpmyadmin/sql/create_tables.sql';
$envFile = '/opt/laravelify/.env';
$passwordFile = $argv[1] ?? '';

if (! is_file($config) || ! is_file($sql)) {
    fwrite(STDERR, "phpMyAdmin control user was not configured because a required file is missing.\n");
    exit(1);
}

$rootPassword = '';
if ($passwordFile !== '' && is_file($passwordFile)) {
    $rootPassword = trim((string) file_get_contents($passwordFile));
} elseif (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if ($key === 'DB_PASSWORD') {
            $rootPassword = trim($value, " \t\"'");
        }
    }
}

if ($rootPassword === '') {
    fwrite(STDERR, "MySQL root password was not found.\n");
    exit(1);
}

$dbuser = $dbpass = $dbname = $dbserver = '';
$dbport = '3306';
include $config;

if ($dbuser === '' || $dbname === '') {
    fwrite(STDERR, "config-db.php has no control user or database name.\n");
    exit(1);
}

if ($dbpass === '') {
    $dbpass = bin2hex(random_bytes(16));
    $written = <<<PHP
<?php
\$dbuser='{$dbuser}';
\$dbpass='{$dbpass}';
\$basepath='';
\$dbname='{$dbname}';
\$dbserver='localhost';
\$dbport='3306';
\$dbtype='mysql';
PHP;
    file_put_contents($config, $written);
    chmod($config, 0640);
}

$escapedUser = str_replace("'", "''", $dbuser);
$escapedPass = str_replace("'", "''", $dbpass);
$escapedDb = str_replace('`', '``', $dbname);
$rootQuoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $rootPassword);

$cnf = tempnam(sys_get_temp_dir(), 'pma-cnf-');
$setup = tempnam(sys_get_temp_dir(), 'pma-sql-');
file_put_contents($cnf, "[client]\nuser=root\npassword=\"{$rootQuoted}\"\n");
chmod($cnf, 0600);
file_put_contents($setup, <<<SQL
CREATE DATABASE IF NOT EXISTS `{$escapedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '{$escapedUser}'@'localhost' IDENTIFIED BY '{$escapedPass}';
ALTER USER '{$escapedUser}'@'localhost' IDENTIFIED BY '{$escapedPass}';
GRANT ALL PRIVILEGES ON `{$escapedDb}`.* TO '{$escapedUser}'@'localhost';
FLUSH PRIVILEGES;
SQL);
chmod($setup, 0600);

$mysql = static function (string $arguments) use ($cnf): int {
    $command = 'mysql --defaults-extra-file='.escapeshellarg($cnf).' --protocol=socket '.$arguments;
    passthru($command, $code);

    return $code;
};

$code = $mysql('< '.escapeshellarg($setup));
unlink($setup);
if ($code !== 0) {
    unlink($cnf);
    fwrite(STDERR, "Could not create the phpMyAdmin control user.\n");
    exit($code);
}

$code = $mysql(escapeshellarg($dbname).' < '.escapeshellarg($sql));
unlink($cnf);
if ($code !== 0) {
    fwrite(STDERR, "Could not import phpMyAdmin configuration storage tables.\n");
    exit($code);
}

echo "phpMyAdmin control user {$dbuser} can use database {$dbname}.\n";
