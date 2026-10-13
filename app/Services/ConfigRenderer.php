<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\Website;

class ConfigRenderer
{
    public function nginx(Website $website): string
    {
        return $website->isLaravel()
            ? $this->laravelNginx($website)
            : $this->nextNginx($website);
    }

    public function process(Website $website): string
    {
        return $website->isLaravel()
            ? $this->supervisor($website)
            : $this->pm2($website);
    }

    public function env(Website $website): string
    {
        return $website->isLaravel()
            ? $this->laravelEnv($website)
            : $this->nextEnv($website);
    }

    public function ingress(CloudflareTunnel $tunnel): string
    {
        $lines = ['ingress:'];

        foreach ($tunnel->websites()->orderBy('domain')->get() as $website) {
            if (! $website->domain) {
                continue;
            }

            $lines[] = '  - hostname: '.$website->domain;
            $lines[] = '    service: http://127.0.0.1:'.($website->http_port ?: 80);
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
stdout_logfile={$path}/storage/logs/socketio.log
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

    private function nextNginx(Website $website): string
    {
        $port = $website->port ?: 3000;

        $listen = $this->listenLines($website);

        return <<<NGINX
# {$this->siteLabel($website)} — Next.js standalone via PM2 on 127.0.0.1:{$port}
# Custom paths (OAuth callbacks, legacy PHP routes) can be added above location /.

server {
{$listen}

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

    private function supervisor(Website $website): string
    {
        $php = '/usr/bin/php'.($website->php_version ?: '8.4');
        $path = $website->deploy_path;
        $slug = $website->slug;
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
stdout_logfile={$path}/storage/logs/reverb.log
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
stdout_logfile={$path}/storage/logs/queue.log
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
stdout_logfile={$path}/storage/logs/scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
{$reverb}
CONF;
    }

    private function pm2(Website $website): string
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
        $name = $website->domain ?: '_';

        return "    listen {$port};\n    listen [::]:{$port};\n    server_name {$name};\n".$this->nginxLogLines($website);
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

    private function nextEnv(Website $website): string
    {
        $port = $website->port ?: 3000;

        return <<<ENV
NODE_ENV=production
PORT={$port}
HOSTNAME=127.0.0.1
ENV;
    }
}
