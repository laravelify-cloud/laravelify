<?php

/**
 * Laravelify one-click sign-in for phpMyAdmin (auth_type "signon").
 *
 * nginx serves this file at /laravelify-signon.php on the phpMyAdmin domain.
 * The panel (behind its login and two-factor check) writes a one-time token
 * hash to storage/app/pma-sso and redirects here with the token. The token
 * works once, within 60 seconds. The MySQL login is read on the server from the
 * panel's .env and never travels in a URL.
 */

declare(strict_types=1);

const TOKEN_DIRECTORY = __DIR__.'/../storage/app/pma-sso';
const PANEL_ENV = __DIR__.'/../.env';
const SESSION_NAME = 'LaravelifySignon';

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name(SESSION_NAME);
session_set_cookie_params(['path' => '/', 'httponly' => true, 'secure' => $https, 'samesite' => 'Lax']);
session_start();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

function page(string $title, string $message, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $title = htmlspecialchars($title, ENT_QUOTES);
    $message = htmlspecialchars($message, ENT_QUOTES);
    echo <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{$title}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a0a0a;color:#f4f4f4;font:14px/1.5 ui-sans-serif,system-ui,sans-serif}
main{width:min(26rem,calc(100% - 2rem));padding:24px;border-radius:12px;background:#161616;box-shadow:0 0 0 1px #333}
h1{margin:0 0 6px;font-size:17px}p{margin:0 0 18px;color:#a1a1a1}a{display:inline-block;margin-right:12px;color:#fcd452;text-decoration:none}a:hover{text-decoration:underline}
</style></head>
<body><main><h1>{$title}</h1><p>{$message}</p><a href="index.php?server=1">Sign in with a password</a></main></body></html>
HTML;
    exit;
}

/**
 * @return array<string, string>
 */
function panelEnv(): array
{
    $values = [];

    foreach (@file(PANEL_ENV, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }

    return $values;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    page('Signed out of phpMyAdmin', 'Open phpMyAdmin from the Laravelify panel to sign in again.');
}

$token = (string) ($_GET['token'] ?? '');

if ($token === '') {
    page('Open phpMyAdmin from Laravelify', 'One-click sign-in starts in the Laravelify panel: use the phpMyAdmin link in its sidebar.');
}

if (preg_match('/^[A-Za-z0-9]{40,100}$/', $token) !== 1) {
    page('Invalid sign-in link', 'This link is not valid. Open phpMyAdmin from the Laravelify panel again.', 400);
}

$file = TOKEN_DIRECTORY.'/'.hash('sha256', $token).'.json';
$data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
@unlink($file);

if (! is_array($data) || (int) ($data['expires'] ?? 0) < time()) {
    page('Sign-in link expired', 'Each link works once, for one minute. Open phpMyAdmin from the Laravelify panel again.', 403);
}

$env = panelEnv();

if (($env['DB_USERNAME'] ?? '') === '') {
    page('MySQL login not found', 'The panel .env has no DB_USERNAME, so phpMyAdmin cannot sign in automatically.', 500);
}

session_regenerate_id(true);
$_SESSION['PMA_single_signon_user'] = $env['DB_USERNAME'];
$_SESSION['PMA_single_signon_password'] = $env['DB_PASSWORD'] ?? '';
$_SESSION['PMA_single_signon_host'] = $env['DB_HOST'] ?? '127.0.0.1';
$_SESSION['PMA_single_signon_port'] = $env['DB_PORT'] ?? '3306';
session_write_close();

header('Location: index.php');
exit;
