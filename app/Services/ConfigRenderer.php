<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\Website;
use App\Support\Redirects;

class ConfigRenderer
{
    public function nginx(Website $website): string
    {
        return match (true) {
            $website->isLaravel() => $this->laravelNginx($website),
            $website->isPhp() => $this->phpNginx($website),
            $website->isNode() => $this->nodeNginx($website),
            default => $this->staticNginx($website),
        };
    }

    /**
     * Supervisor workers (Laravel), a PM2 file (Node servers), or nothing (plain PHP and static sites).
     */
    public function process(Website $website): string
    {
        return match (true) {
            $website->isLaravel() => $this->supervisor($website),
            $website->isNode() => $this->pm2($website),
            default => '',
        };
    }

    public function env(Website $website): string
    {
        return match (true) {
            $website->isLaravel() => $this->laravelEnv($website),
            $website->isPhp() => $this->phpEnv($website),
            $website->isNode() => $this->nodeEnv($website),
            default => $this->staticEnv($website),
        };
    }

    /**
     * The folder nginx serves for a PHP or static site.
     */
    public function webRoot(Website $website): string
    {
        $path = rtrim((string) $website->deploy_path, '/');
        $folder = trim((string) $website->public_dir, '/');

        if ($website->isLaravel()) {
            return $path.'/public';
        }

        if ($website->isPhp()) {
            return $folder === '' ? $path.'/public' : ($folder === '.' ? $path : $path.'/'.$folder);
        }

        // Built sites are served through a symlink the deploy points at the build output.
        if ($website->type !== 'static') {
            return $path.'/public_html';
        }

        return $folder === '' || $folder === '.' ? $path : $path.'/'.$folder;
    }

    public function ingress(CloudflareTunnel $tunnel): string
    {
        $lines = ['ingress:'];

        foreach ($tunnel->websites()->orderBy('domain')->get() as $website) {
            foreach ($website->hostnames() as $domain) {
                $lines[] = '  - hostname: '.$domain;
                $lines[] = '    service: http://127.0.0.1:'.($website->http_port ?: 80);
            }
        }

        $lines[] = '  - service: http_status:404';

        return implode("\n", $lines)."\n";
    }

    public function refreshTunnel(?CloudflareTunnel $tunnel): void
    {
        if (! $tunnel) {
            return;
        }

        $tunnel->ingress = $this->ingress($tunnel);
        $tunnel->save();
        app(HostWriter::class)->writeTunnel($tunnel);
    }

    public function fillNewWebsite(Website $website): void
    {
        $website->nginx_config = $this->nginx($website);
        $website->process_config = $this->process($website);
        $website->env_content = $this->env($website);
    }

    public function attachSocketIo(Website $website): bool
    {
        if (! is_file($website->deploy_path.'/realtime/server.js')) {
            return false;
        }

        $nginx = (string) $website->nginx_config;

        if (! str_contains($nginx, 'location /socket.io/')) {
            $proxy = <<<'NGINX'

    location /socket.io/ {
        proxy_pass http://127.0.0.1:3015;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 86400;
    }
NGINX;
            $updated = preg_replace('/\n    location \/ \{/', $proxy."\n    location / {", $nginx, 1);
            $website->nginx_config = is_string($updated) ? $updated : $nginx.$proxy;
        }

        $process = (string) $website->process_config;
        $script = base_path('scripts/with-node.sh');

        if (! str_contains($process, 'realtime/server.js') && ! str_contains($process, 'node server.js')) {
            $slug = $website->slug;
            $path = $website->deploy_path;
            $logs = self::supervisorLogDirectory($slug);
            $website->process_config = rtrim($process).<<<CONF


[program:{$slug}-socketio]
command=/bin/bash {$script} 22 node server.js
directory={$path}/realtime
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
environment=HOME="/var/www"
redirect_stderr=true
stdout_logfile={$logs}/socketio.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
CONF;
        }

        $website->save();

        return true;
    }

    private function laravelNginx(Website $website): string
    {
        $php = $website->php_version ?: '8.4';
        $reverb = '';

        if ($website->enable_reverb && $website->reverb_port) {
            $port = $website->reverb_port;
            $reverb = <<<NGINX

    location /app {
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 86400;
    }
NGINX;
        }

        $listen = $this->listenLines($website);

        return <<<NGINX
# {$this->siteLabel($website)} — Laravel (PHP {$php}-FPM)
# Add extra locations here (OAuth callbacks, Socket.IO, payment webhooks).

server {
{$listen}

    root {$website->deploy_path}/public;
    index index.php;
    client_max_body_size 1024M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php{$php}-fpm.sock;
        fastcgi_read_timeout 300;
    }
{$reverb}
    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    private function phpNginx(Website $website): string
    {
        $php = $website->php_version ?: '8.4';
        $root = $this->webRoot($website);
        $listen = $this->listenLines($website);

        return <<<NGINX
# {$this->siteLabel($website)} — PHP (PHP {$php}-FPM)
# Front-controller apps (Symfony, Slim, CodeIgniter) work as they are. Add extra locations here.

server {
{$listen}

    root {$root};
    index index.php index.html;
    client_max_body_size 256M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php{$php}-fpm.sock;
        fastcgi_read_timeout 300;
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    private function nodeNginx(Website $website): string
    {
        $port = $website->port ?: 3000;
        $label = $website->typeLabel();
        $listen = $this->listenLines($website);

        return <<<NGINX
# {$this->siteLabel($website)} — {$label} via PM2 on 127.0.0.1:{$port}
# Custom paths (OAuth callbacks, legacy PHP routes) can be added above location /.

server {
{$listen}
    client_max_body_size 256M;

    location / {
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
    }
}
NGINX;
    }

    private function staticNginx(Website $website): string
    {
        $root = $this->webRoot($website);
        $label = $website->typeLabel();
        $listen = $this->listenLines($website);
        $spa = (bool) ($website->definition()['spa'] ?? false);
        $fallback = $spa
            ? 'try_files $uri $uri/ /index.html;'
            : 'try_files $uri $uri.html $uri/ =404;';
        $note = $spa
            ? '# Unknown paths fall back to index.html, so client-side routes work on refresh.'
            : '# Unknown paths answer 404.';

        return <<<NGINX
# {$this->siteLabel($website)} — {$label}, static files served by nginx
{$note}

server {
{$listen}

    root {$root};
    index index.html;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml text/plain;

    location / {
        {$fallback}
    }

    # The page itself is always fresh; hashed assets can be cached.
    location = /index.html {
        add_header Cache-Control "no-cache";
    }

    location ~* \\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|avif|ico)\$ {
        expires 7d;
        add_header Cache-Control "public";
        access_log off;
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    /**
     * Supervisor writes its programs' logs as root, so they live in a root-owned folder: a log
     * file inside the site folder could be swapped for a link to any file on the server.
     */
    public static function supervisorLogDirectory(string $slug): string
    {
        return rtrim((string) config('laravelify.site_log_dir'), '/').'/'.$slug;
    }

    /**
     * Make a site's Supervisor config safe to apply: every program runs as the site user, and
     * its logs go to the site's log folder (a program's own log name is kept). host-apply.sh
     * refuses anything else. HOME follows the user: the site's own home folder for its own
     * user, /var/www for the shared one.
     */
    public static function secureSupervisor(string $config, string $slug, ?string $user = null, ?string $home = null): string
    {
        $logs = self::supervisorLogDirectory($slug);
        $user ??= (string) (config('laravelify.site_user') ?: 'www-data');
        $home ??= '/var/www';
        $homes = ['/var/www', rtrim((string) config('laravelify.site_users_root'), '/').'/'.$slug.'/home'];
        $lines = preg_split('/\R/', $config) ?: [];
        $output = [];
        $program = null;
        $hasUser = false;

        $closeProgram = function () use (&$output, &$program, &$hasUser, $user): void {
            if ($program !== null && ! $hasUser) {
                // Insert after the last setting of the section, before trailing blank lines.
                $at = count($output);

                while ($at > 0 && trim($output[$at - 1]) === '') {
                    $at--;
                }

                array_splice($output, $at, 0, ['user='.$user]);
            }

            $program = null;
            $hasUser = false;
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $match) === 1) {
                $closeProgram();
                $program = str_starts_with($match[1], 'program:') ? substr($match[1], 8) : null;
                $output[] = $line;

                continue;
            }

            if ($program !== null && preg_match('/^user\s*[=:]/i', $trimmed) === 1) {
                $output[] = 'user='.$user;
                $hasUser = true;

                continue;
            }

            if ($program !== null && preg_match('/^environment\s*[=:]/i', $trimmed) === 1) {
                foreach ($homes as $known) {
                    $line = str_replace('HOME="'.$known.'"', 'HOME="'.$home.'"', $line);
                }

                $output[] = $line;

                continue;
            }

            if ($program !== null && preg_match('/^(stdout_logfile|stderr_logfile)\s*[=:]\s*(\S+)\s*(?:[;#].*)?$/i', $trimmed, $match) === 1) {
                $value = $match[2];

                if (! in_array(strtoupper($value), ['AUTO', 'NONE'], true) && ! str_starts_with($value, $logs.'/')) {
                    $name = preg_replace('/[^A-Za-z0-9_.-]/', '-', basename($value)) ?: $program.'.log';
                    $value = $logs.'/'.(str_ends_with($name, '.log') ? $name : $name.'.log');
                }

                $output[] = $match[1].'='.$value;

                continue;
            }

            $output[] = $line;
        }

        $closeProgram();

        return implode("\n", $output);
    }

    /**
     * The PHP-FPM socket in a site's nginx file: the site's own pool once it has its own user
     * (host-apply.sh writes that pool), the shared pool of its PHP version before.
     */
    public static function withSiteSocket(string $nginx, Website $website, bool $ownUser): string
    {
        $socket = $ownUser
            ? self::siteSocket((string) $website->slug)
            : 'unix:/run/php/php'.($website->php_version ?: '8.4').'-fpm.sock';

        return (string) preg_replace('#unix:/run/php/(?:php[0-9]+\.[0-9]+-fpm|site-[a-z0-9-]+)\.sock#', $socket, $nginx);
    }

    public static function siteSocket(string $slug): string
    {
        return 'unix:/run/php/site-'.$slug.'.sock';
    }

    private function supervisor(Website $website): string
    {
        $php = '/usr/bin/php'.($website->php_version ?: '8.4');
        $path = $website->deploy_path;
        $slug = $website->slug;
        $logs = self::supervisorLogDirectory($slug);
        $reverb = '';

        if ($website->enable_reverb && $website->reverb_port) {
            $port = $website->reverb_port;
            $reverb = <<<CONF

[program:{$slug}-reverb]
command={$php} {$path}/artisan reverb:start --host=127.0.0.1 --port={$port}
directory={$path}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile={$logs}/reverb.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
CONF;
        }

        return <<<CONF
; {$slug} — queue, scheduler, and optional Reverb.
; Add more programs below (balances:listen, wallets:listen, a Socket.IO start script).
; Change --queue= to the queues this app uses.

[program:{$slug}-queue]
command={$php} {$path}/artisan queue:work redis --queue=default --sleep=1 --tries=3 --timeout=120 --max-time=3600
directory={$path}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile={$logs}/queue.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stopwaitsecs=120

[program:{$slug}-scheduler]
command={$php} {$path}/artisan schedule:work
directory={$path}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
redirect_stderr=true
stdout_logfile={$logs}/scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
{$reverb}
CONF;
    }

    private function pm2(Website $website): string
    {
        return $website->isNextjs() ? $this->nextPm2($website) : $this->nodePm2($website);
    }

    private function nextPm2(Website $website): string
    {
        $port = $website->port ?: 3000;
        $path = $website->deploy_path;
        $slug = $website->slug;

        return <<<JS
module.exports = {
  apps: [
    {
      name: "{$slug}",
      cwd: "{$path}/.next/standalone",
      script: "server.js",
      interpreter: "node",
      env_file: "{$path}/.env",
      env: {
        NODE_ENV: "production",
        PORT: "{$port}",
        HOSTNAME: "127.0.0.1",
      },
    },
  ],
};
JS;
    }

    /**
     * Express, NestJS, Nuxt, SvelteKit, or any Node app: run the start command from the
     * site folder, with the Node major version chosen for the site.
     */
    private function nodePm2(Website $website): string
    {
        $port = $website->port ?: 3000;
        $path = $website->deploy_path;
        $slug = $website->slug;
        $node = $website->node_version ?: '22';
        $command = $website->startCommand() ?: 'npm start';
        $withNode = base_path('scripts/with-node.sh');
        $origin = $website->publicUrl();
        $extra = '';

        if ($website->type === 'nuxt') {
            $extra = "\n        NITRO_HOST: \"127.0.0.1\",\n        NITRO_PORT: \"{$port}\",";
        } elseif ($website->type === 'sveltekit' && $origin) {
            // adapter-node rejects form posts from an origin it does not know.
            $extra = "\n        ORIGIN: ".json_encode($origin, JSON_UNESCAPED_SLASHES).',';
        }

        return <<<JS
module.exports = {
  apps: [
    {
      name: "{$slug}",
      cwd: "{$path}",
      script: "/bin/bash",
      args: [{$this->jsString($withNode)}, "{$node}", "bash", "-c", {$this->jsString('exec '.$command)}],
      interpreter: "none",
      env_file: "{$path}/.env",
      env: {
        NODE_ENV: "production",
        PORT: "{$port}",
        HOST: "127.0.0.1",
        HOSTNAME: "127.0.0.1",{$extra}
      },
    },
  ],
};
JS;
    }

    private function jsString(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function laravelEnv(Website $website): string
    {
        $name = str_replace(['"', "\n"], '', $website->name);
        $db = $website->db_name ?: '';
        $user = $website->db_username ?: '';
        $password = $website->db_password ?: '';

        return <<<ENV
APP_NAME="{$name}"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL={$website->publicUrl()}

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE={$db}
DB_USERNAME={$user}
DB_PASSWORD={$password}

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

BROADCAST_CONNECTION=reverb
REVERB_SERVER_HOST=127.0.0.1
REVERB_SERVER_PORT={$website->reverb_port}
ENV;
    }

    private function siteLabel(Website $website): string
    {
        return $website->domain ?: 'port '.($website->http_port ?: 80);
    }

    private function listenLines(Website $website): string
    {
        $port = $website->http_port ?: 80;
        $name = $website->domains() === [] ? '_' : implode(' ', $website->domains());

        return "    listen {$port};\n    listen [::]:{$port};\n    server_name {$name};\n".$this->nginxLogLines($website);
    }

    /**
     * The site's nginx file with its server_name line set to all its domains (the first one in the
     * file is replaced; everything else stays as it is, including hand-made changes).
     */
    public function withServerNames(Website $website, string $nginx): string
    {
        $names = $website->domains() === [] ? '_' : implode(' ', $website->domains());
        $updated = preg_replace('/(^[ 	]*|\{\s*)server_name\s+[^;]*;/m', '${1}server_name '.$names.';', $nginx, 1, $count);

        return $count === 1 && is_string($updated) ? $updated : $nginx;
    }

    /**
     * The site's nginx file with its managed redirects (Redirects tab) rewritten: path redirects as
     * locations right after its first server_name, domain redirects as their own server blocks at the end.
     */
    public function withRedirects(Website $website, string $nginx): string
    {
        $nginx = (string) preg_replace('/\r?\n*# laravelify-redirects:start.*?# laravelify-redirects:end[ \t]*\r?\n?/s', "\n", $nginx);
        $nginx = (string) preg_replace('/[ \t]*# laravelify-path-redirects:start.*?# laravelify-path-redirects:end[ \t]*\r?\n/s', '', $nginx);
        $blocks = Redirects::nginx($website);

        if ($blocks['paths'] !== '') {
            $nginx = (string) preg_replace_callback('/^[ \t]*server_name\b[^;]*;[^\r\n]*$/m', fn (array $match): string => $match[0]."\n".$blocks['paths'], $nginx, 1);
        }

        return $blocks['servers'] === '' ? rtrim($nginx)."\n" : rtrim($nginx)."\n\n".$blocks['servers']."\n";
    }

    /**
     * Per-site nginx log files, so the panel can show this site's access and error log.
     */
    public function nginxLogLines(Website $website): string
    {
        return "    access_log /var/log/nginx/laravelify-{$website->slug}.access.log;\n"
            ."    error_log /var/log/nginx/laravelify-{$website->slug}.error.log;";
    }

    public function hasNginxLogs(Website $website): bool
    {
        return str_contains((string) $website->nginx_config, "laravelify-{$website->slug}.access.log");
    }

    /**
     * Insert the per-site log lines after the first server_name of an existing config.
     */
    public function addNginxLogs(Website $website): bool
    {
        if ($this->hasNginxLogs($website)) {
            return false;
        }

        $updated = preg_replace('/^(\s*server_name[^;]*;)$/m', '$1'."\n".$this->nginxLogLines($website), (string) $website->nginx_config, 1, $count);

        if (! is_string($updated) || $count === 0) {
            return false;
        }

        $website->nginx_config = $updated;

        return true;
    }

    private function phpEnv(Website $website): string
    {
        $name = str_replace(['"', "\n"], '', $website->name);
        $db = $website->db_name ?: '';
        $user = $website->db_username ?: '';
        $password = $website->db_password ?: '';

        return <<<ENV
APP_NAME="{$name}"
APP_ENV=production
APP_DEBUG=false
APP_URL={$website->publicUrl()}

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE={$db}
DB_USERNAME={$user}
DB_PASSWORD={$password}
ENV;
    }

    private function nodeEnv(Website $website): string
    {
        $port = $website->port ?: 3000;

        return <<<ENV
NODE_ENV=production
PORT={$port}
HOSTNAME=127.0.0.1
ENV;
    }

    private function staticEnv(Website $website): string
    {
        return $website->type === 'static'
            ? "# Static HTML has no build, so this file is not used.\n"
            : "# Available while the site builds, for example:\n# VITE_API_URL=https://api.example.com\n";
    }
}
