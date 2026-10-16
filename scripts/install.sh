#!/usr/bin/env bash
# Install Laravelify on Ubuntu, the same way Coolify is installed:
#   curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash
#
# Downloads the latest release from GitHub (LARAVELIFY_REPO) unless a local
# copy is given with LARAVELIFY_SOURCE.
#
# Asks for the MySQL root password, the panel domain, the phpMyAdmin
# domain, and a Cloudflare tunnel token.
# Installs MySQL, Redis, phpMyAdmin, Node.js, PM2, PHP-FPM, nginx,
# cloudflared, and a firewall that allows only SSH, 80, and 443.
#
# Optional environment variables:
#   MYSQL_ROOT_PASSWORD       skip the password prompt
#   PANEL_DOMAIN              skip the panel domain prompt
#   PHPMYADMIN_DOMAIN         skip the phpMyAdmin domain prompt
#   CLOUDFLARE_TUNNEL_TOKEN   skip the tunnel token prompt
#   LARAVELIFY_VERSION        release to install, like 1.2.0 (default: latest)
#   LARAVELIFY_REPO           GitHub owner/name to download releases from
#   LARAVELIFY_SOURCE         directory to copy into /opt/laravelify instead
#   LARAVELIFY_ARCHIVE_URL    tar.gz of the panel to download instead

set -euo pipefail
trap 'rm -rf /tmp/laravelify-install' EXIT

# The release workflow replaces this with the real repository when it uploads the script.
LARAVELIFY_REPO="${LARAVELIFY_REPO:-laravelify-cloud/laravelify}"
LARAVELIFY_VERSION="${LARAVELIFY_VERSION:-latest}"

if [[ "$LARAVELIFY_VERSION" == "latest" ]]; then
  DEFAULT_ARCHIVE_URL="https://github.com/${LARAVELIFY_REPO}/releases/latest/download/laravelify.tar.gz"
else
  DEFAULT_ARCHIVE_URL="https://github.com/${LARAVELIFY_REPO}/releases/download/v${LARAVELIFY_VERSION#v}/laravelify.tar.gz"
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script as root: curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

read_tty() {
  if [[ -r /dev/tty ]]; then
    read -r "$@" </dev/tty
  else
    return 1
  fi
}

# Already installed? Update Laravelify only: the new release, migrations, and a restart of
# its workers. PHP, MySQL, nginx, Node, and the other packages are not touched.
# LARAVELIFY_REINSTALL=1 skips this and runs the full install (a repair).
INSTALLED_ROOT="${LARAVELIFY_ROOT:-/opt/laravelify}"

if [[ "${LARAVELIFY_REINSTALL:-}" != "1" && -z "${LARAVELIFY_SOURCE:-}" \
  && -f "${INSTALLED_ROOT}/artisan" && -f "${INSTALLED_ROOT}/.env" && -f "${INSTALLED_ROOT}/vendor/autoload.php" ]]; then
  UPDATE_DIR="$(mktemp -d /tmp/laravelify-update.XXXXXX)"
  UPDATE_ARCHIVE_URL="${LARAVELIFY_ARCHIVE_URL:-$DEFAULT_ARCHIVE_URL}"
  INSTALLED_VERSION="$(tr -d '\r\n' < "${INSTALLED_ROOT}/VERSION" 2>/dev/null || true)"
  echo "Laravelify ${INSTALLED_VERSION:-(unknown version)} is already installed. Checking the release..."

  if ! curl -fsSL "$UPDATE_ARCHIVE_URL" | tar -xz -C "$UPDATE_DIR" --strip-components=1; then
    rm -rf "$UPDATE_DIR"
    echo "Could not download ${UPDATE_ARCHIVE_URL}." >&2
    echo "Check LARAVELIFY_REPO / LARAVELIFY_VERSION, or that the GitHub release has laravelify.tar.gz attached." >&2
    exit 1
  fi

  echo "  Installed: ${INSTALLED_VERSION:-unknown}"
  echo "  Release:   $(tr -d '\r\n' < "${UPDATE_DIR}/VERSION" 2>/dev/null || echo unknown)"
  UPDATE_ANSWER=""
  # No terminal (automated run): update only.
  read_tty -p "Update Laravelify only? Packages and settings stay as they are. [Y/n] (n = reinstall everything) " UPDATE_ANSWER || UPDATE_ANSWER="y"
  echo

  if [[ ! "$UPDATE_ANSWER" =~ ^[Nn] ]]; then
    UPDATE_STATUS=0
    LARAVELIFY_SOURCE="$UPDATE_DIR" LARAVELIFY_ROOT="$INSTALLED_ROOT" LARAVELIFY_RELEASE=1 bash "${UPDATE_DIR}/scripts/update.sh" || UPDATE_STATUS=$?
    rm -rf "$UPDATE_DIR"
    exit "$UPDATE_STATUS"
  fi

  rm -rf "$UPDATE_DIR"
fi

# Re-running the installer (to repair or finish an install) offers the answers
# from last time. They are saved root-only in /etc/laravelify/install.env, or,
# for older installs, read back from the existing installation.
STATE_FILE=/etc/laravelify/install.env

env_value() {
  grep -m1 "^$1=" /opt/laravelify/.env 2>/dev/null | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

previous_settings() {
  if [[ -f "$STATE_FILE" ]]; then
    # shellcheck disable=SC1090
    . "$STATE_FILE"
  elif [[ -f /opt/laravelify/.env ]]; then
    PREV_PANEL_DOMAIN="$(env_value APP_URL | sed -e 's#^https\?://##' -e 's#/.*$##')"
    if [[ "$(env_value DB_USERNAME)" == "root" ]]; then
      PREV_MYSQL_ROOT_PASSWORD="$(env_value DB_PASSWORD)"
    fi
    PREV_PHPMYADMIN_DOMAIN="$(grep -m1 -oP 'server_name\s+\K[^;]+' /etc/nginx/sites-available/laravelify-phpmyadmin.conf 2>/dev/null || true)"
    PREV_CLOUDFLARE_TUNNEL_TOKEN="$(grep -h -oP -- '--token "\K[^"]+' /etc/systemd/system/laravelify-tunnel-*.service 2>/dev/null | head -n 1 || true)"
  fi

  [[ -n "${PREV_PANEL_DOMAIN:-}" ]]
}

masked() {
  if [[ -n "$1" ]]; then echo "******** (saved)"; else echo "(not found, you will be asked)"; fi
}

if [[ -z "${MYSQL_ROOT_PASSWORD:-}" || -z "${PANEL_DOMAIN:-}" || -z "${PHPMYADMIN_DOMAIN:-}" || -z "${CLOUDFLARE_TUNNEL_TOKEN:-}" ]] && previous_settings; then
  echo
  echo "Found the settings from a previous install:"
  echo "  Panel domain:            ${PREV_PANEL_DOMAIN}"
  echo "  phpMyAdmin domain:       ${PREV_PHPMYADMIN_DOMAIN:-(not found, you will be asked)}"
  echo "  MySQL root password:     $(masked "${PREV_MYSQL_ROOT_PASSWORD:-}")"
  echo "  Cloudflare tunnel token: $(masked "${PREV_CLOUDFLARE_TUNNEL_TOKEN:-}")"
  REUSE_ANSWER=""
  # No terminal (automated install): reuse them.
  read_tty -p "Use these settings? [Y/n] " REUSE_ANSWER || REUSE_ANSWER="y"
  echo
  if [[ ! "$REUSE_ANSWER" =~ ^[Nn] ]]; then
    MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-${PREV_MYSQL_ROOT_PASSWORD:-}}"
    PANEL_DOMAIN="${PANEL_DOMAIN:-${PREV_PANEL_DOMAIN:-}}"
    PHPMYADMIN_DOMAIN="${PHPMYADMIN_DOMAIN:-${PREV_PHPMYADMIN_DOMAIN:-}}"
    CLOUDFLARE_TUNNEL_TOKEN="${CLOUDFLARE_TUNNEL_TOKEN:-${PREV_CLOUDFLARE_TUNNEL_TOKEN:-}}"
  fi
fi

if [[ -z "${MYSQL_ROOT_PASSWORD:-}" ]]; then
  read_tty -s -p "MySQL root password: " MYSQL_ROOT_PASSWORD
  echo
  read_tty -s -p "MySQL root password again: " MYSQL_ROOT_PASSWORD_CONFIRM
  echo
  if [[ "$MYSQL_ROOT_PASSWORD" != "$MYSQL_ROOT_PASSWORD_CONFIRM" ]]; then
    echo "The passwords do not match." >&2
    exit 1
  fi
fi

if [[ -z "$MYSQL_ROOT_PASSWORD" || "$MYSQL_ROOT_PASSWORD" =~ [[:space:]] ]]; then
  echo "The MySQL root password is required and cannot contain spaces." >&2
  exit 1
fi

if [[ -z "${PANEL_DOMAIN:-}" ]]; then
  read_tty -p "Laravelify panel domain (example: panel.example.com): " PANEL_DOMAIN
fi

if [[ -z "${PHPMYADMIN_DOMAIN:-}" ]]; then
  read_tty -p "phpMyAdmin domain (example: phpmyadmin.example.com): " PHPMYADMIN_DOMAIN
fi

if [[ ! "$PANEL_DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
  echo "The panel domain is not a hostname." >&2
  exit 1
fi

if [[ ! "$PHPMYADMIN_DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
  echo "The phpMyAdmin domain is not a hostname." >&2
  exit 1
fi

if [[ "$PANEL_DOMAIN" == "$PHPMYADMIN_DOMAIN" ]]; then
  echo "The panel domain and the phpMyAdmin domain must be different." >&2
  exit 1
fi

if [[ -z "${CLOUDFLARE_TUNNEL_TOKEN:-}" ]]; then
  read_tty -s -p "Cloudflare tunnel token: " CLOUDFLARE_TUNNEL_TOKEN
  echo
fi

if [[ -z "$CLOUDFLARE_TUNNEL_TOKEN" || "$CLOUDFLARE_TUNNEL_TOKEN" =~ [[:space:]] ]]; then
  echo "The Cloudflare tunnel token is required and cannot contain spaces." >&2
  exit 1
fi

# Save the answers (root-only) so a re-run can offer them again.
install -d -m 700 /etc/laravelify
(
  umask 077
  printf 'PREV_MYSQL_ROOT_PASSWORD=%q\nPREV_PANEL_DOMAIN=%q\nPREV_PHPMYADMIN_DOMAIN=%q\nPREV_CLOUDFLARE_TUNNEL_TOKEN=%q\n' \
    "$MYSQL_ROOT_PASSWORD" "$PANEL_DOMAIN" "$PHPMYADMIN_DOMAIN" "$CLOUDFLARE_TUNNEL_TOKEN" > "$STATE_FILE"
)
chmod 600 "$STATE_FILE"

install -d -m 700 /tmp/laravelify-install
printf '%s' "$CLOUDFLARE_TUNNEL_TOKEN" > /tmp/laravelify-install/tunnel-token
chmod 600 /tmp/laravelify-install/tunnel-token
unset CLOUDFLARE_TUNNEL_TOKEN

# Get the panel first: later steps use its helper scripts, and a bad download
# should fail now, not after ten minutes of package installs.
APP_ROOT="/opt/laravelify"
SOURCE=""
if [[ -n "${LARAVELIFY_SOURCE:-}" && -f "${LARAVELIFY_SOURCE}/artisan" ]]; then
  SOURCE="$LARAVELIFY_SOURCE"
elif [[ -n "${BASH_SOURCE[0]:-}" && -f "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/artisan" ]]; then
  SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
else
  # curl | bash: download the release (built assets included, vendor installed below).
  ARCHIVE_URL="${LARAVELIFY_ARCHIVE_URL:-$DEFAULT_ARCHIVE_URL}"
  echo "Downloading Laravelify from ${ARCHIVE_URL}"
  install -d "$APP_ROOT"
  if ! curl -fsSL "$ARCHIVE_URL" | tar -xz -C "$APP_ROOT" --strip-components=1; then
    echo "Could not download ${ARCHIVE_URL}." >&2
    echo "Check LARAVELIFY_REPO / LARAVELIFY_VERSION, or that the GitHub release has laravelify.tar.gz attached." >&2
    exit 1
  fi
fi

if [[ -n "$SOURCE" && "$SOURCE" != "$APP_ROOT" ]]; then
  install -d "$APP_ROOT"
  tar -C "$SOURCE" --exclude=vendor --exclude=node_modules --exclude=storage/logs --exclude=.git -cf - . | tar -C "$APP_ROOT" -xf -
fi

if [[ ! -f "$APP_ROOT/artisan" ]]; then
  echo "The panel source was not found."
  echo "Re-run with LARAVELIFY_SOURCE=/path/to/laravelify, LARAVELIFY_VERSION=x.y.z, or LARAVELIFY_ARCHIVE_URL=https://..."
  exit 1
fi

. /etc/os-release
CODENAME="${VERSION_CODENAME}"

# apt-get install only for the packages that are not installed yet (quiet when all are).
package_installed() {
  dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'
}

apt_install() {
  local missing=() package
  for package in "$@"; do
    package_installed "$package" || missing+=("$package")
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    apt-get install -y "${missing[@]}"
  fi
}

drop_extra_sources() {
  local pattern="$1"
  local file
  shopt -s nullglob
  for file in /etc/apt/sources.list.d/*; do
    [[ -f "$file" ]] || continue
    if grep -q "$pattern" "$file"; then
      echo "Removing apt source ${file}"
      rm -f "$file"
    fi
  done
}

# A previous run may have added the Ondřej PPA. Ubuntu 26.04 has no Release
# file there, and apt then refuses every update.
if awk -v v="${VERSION_ID:-0}" 'BEGIN { exit !(v+0 >= 26.04) }'; then
  drop_extra_sources "ppa.launchpadcontent.net/ondrej/php"
fi

apt-get update
apt_install ca-certificates curl gnupg lsb-release software-properties-common \
  nginx supervisor git unzip redis-server ufw debconf-utils rclone

# The PHP repository is added once.
php_repository_present() {
  grep -rqsE 'ondrej/php|packages\.sury\.org/php' /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null
}

if ! php_repository_present; then
  if awk -v v="${VERSION_ID:-0}" 'BEGIN { exit !(v+0 >= 26.04) }'; then
    curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    dpkg -i /tmp/debsuryorg-archive-keyring.deb
    rm -f /tmp/debsuryorg-archive-keyring.deb
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
  else
    add-apt-repository -y ppa:ondrej/php
  fi
  apt-get update
fi

install_php() {
  local version="$1"
  local packages=()
  local extension

  for extension in fpm cli mysql xml mbstring curl zip redis bcmath intl; do
    packages+=("php${version}-${extension}")
  done

  local missing=()
  local package
  for package in "${packages[@]}"; do
    package_installed "$package" || missing+=("$package")
  done

  if [[ ${#missing[@]} -eq 0 ]]; then
    echo "PHP ${version} is already installed."
  elif apt-cache show "php${version}-fpm" >/dev/null 2>&1; then
    apt-get install -y "${missing[@]}"
  else
    echo "Skipping PHP ${version}: the package is not in this archive."
  fi
}

for version in 8.0 8.1 8.2 8.3 8.4 8.5; do
  install_php "${version}"
done

PANEL_PHP=""
for version in 8.4 8.3 8.5 8.2 8.1 8.0; do
  if [[ -x "/usr/bin/php${version}" ]]; then
    PANEL_PHP="$version"
    update-alternatives --install /usr/bin/php php "/usr/bin/php${version}" 84
    break
  fi
done

if [[ -z "$PANEL_PHP" ]]; then
  echo "No PHP CLI was installed." >&2
  exit 1
fi

if package_installed mysql-community-server || package_installed mysql-server; then
  echo "MySQL is already installed."
else
  install -d -m 0755 /usr/share/keyrings
  if [[ ! -f /usr/share/keyrings/mysql-archive-keyring.gpg ]]; then
    curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 | gpg --dearmor -o /usr/share/keyrings/mysql-archive-keyring.gpg
  fi

  cat > /etc/apt/sources.list.d/mysql-8.4-lts.list <<EOF
deb [signed-by=/usr/share/keyrings/mysql-archive-keyring.gpg] http://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-8.4-lts
EOF

  if ! apt-get update; then
    echo "MySQL 8.4 repository has no packages for ${CODENAME}. Using the Ubuntu MySQL package."
    rm -f /etc/apt/sources.list.d/mysql-8.4-lts.list
    apt-get update
    apt-get install -y mysql-server
  else
    apt-get install -y mysql-community-server mysql-community-client || apt-get install -y mysql-server
  fi
fi

# Ubuntu 26.04 rejects debconf answers for templates that are not loaded yet.
preseed_phpmyadmin() {
  apt-get install -y -d phpmyadmin || return 0
  local deb tmp
  deb="$(find /var/cache/apt/archives -maxdepth 1 -name 'phpmyadmin_*.deb' -print -quit 2>/dev/null || true)"
  [[ -n "$deb" ]] || return 0
  tmp="$(mktemp -d)"
  if dpkg-deb -e "$deb" "$tmp" && [[ -f "$tmp/templates" ]]; then
    debconf-loadtemplate phpmyadmin "$tmp/templates" || true
    if grep -q '^Template: phpmyadmin/dbconfig-install$' "$tmp/templates"; then
      echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    fi
    if grep -q '^Template: phpmyadmin/reconfigure-webserver$' "$tmp/templates"; then
      echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect" | debconf-set-selections
    fi
  fi
  rm -rf "$tmp"
}
if package_installed phpmyadmin; then
  echo "phpMyAdmin is already installed."
else
  preseed_phpmyadmin
  apt-get install -y phpmyadmin || echo "phpMyAdmin package was not installed. nginx still points at /usr/share/phpmyadmin."
fi

if ! command -v node >/dev/null 2>&1; then
  if curl -fsSL https://deb.nodesource.com/setup_22.x | bash -; then
    apt-get install -y nodejs
  else
    echo "NodeSource does not support ${CODENAME}. Using the Ubuntu Node.js package."
    drop_extra_sources "deb.nodesource.com"
    apt-get update
    apt-get install -y nodejs npm
  fi
fi

if ! command -v pm2 >/dev/null 2>&1 || ! command -v n >/dev/null 2>&1; then
  npm install -g pm2 n
fi
# Node sites run in www-data's PM2 daemon; scripts/privileges.sh enables pm2-www-data below.
for node_major in 20 22 24; do
  if ! compgen -G "/usr/local/n/versions/node/${node_major}.*" >/dev/null; then
    n "$node_major" || true
  fi
done

if ! command -v composer >/dev/null 2>&1; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

install -d -m 700 /tmp/laravelify-install
printf '%s' "$MYSQL_ROOT_PASSWORD" > /tmp/laravelify-install/password
chmod 600 /tmp/laravelify-install/password
php <<'PHP'
<?php
$password = file_get_contents('/tmp/laravelify-install/password');
$quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
file_put_contents('/tmp/laravelify-install/client.cnf', "[client]\nuser=root\npassword=\"{$quoted}\"\n");
$escaped = str_replace("'", "''", $password);
file_put_contents('/tmp/laravelify-install/setup.sql', <<<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '{$escaped}';
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY '{$escaped}';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY '{$escaped}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS laravelify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL);
PHP

if mysql --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
  mysql --protocol=socket -uroot < /tmp/laravelify-install/setup.sql
else
  mysql --defaults-extra-file=/tmp/laravelify-install/client.cnf --protocol=socket < /tmp/laravelify-install/setup.sql
fi

if [[ -f /etc/phpmyadmin/config-db.php && -f /usr/share/phpmyadmin/sql/create_tables.sql ]]; then
  php "$APP_ROOT/scripts/configure-phpmyadmin.php" /tmp/laravelify-install/password
fi

install -d /etc/mysql/mysql.conf.d
cat > /etc/mysql/mysql.conf.d/laravelify.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
mysqlx = 0
port = 3306
character-set-server = utf8mb4
collation-server = utf8mb4_general_ci
# Binary logging is on in MySQL 8: without this, site users cannot create triggers (error 1419).
log_bin_trust_function_creators = 1
EOF
systemctl restart mysql
mysql --defaults-extra-file=/tmp/laravelify-install/client.cnf --protocol=socket -e "SET PERSIST default_collation_for_utf8mb4 = 'utf8mb4_general_ci'; SET PERSIST log_bin_trust_function_creators = 1"

install -d /etc/phpmyadmin/conf.d
cat > /etc/phpmyadmin/conf.d/laravelify-collation.php <<'EOF'
<?php
$cfg['DefaultCharset'] = 'utf8mb4';
$cfg['DefaultConnectionCollation'] = 'utf8mb4_general_ci';
EOF

install -d /etc/redis
cat > /etc/redis/laravelify.conf <<'EOF'
bind 127.0.0.1
port 6379
supervised systemd
appendonly yes
EOF
if [[ -f /etc/redis/redis.conf ]] && ! grep -q '/etc/redis/laravelify.conf' /etc/redis/redis.conf; then
  printf '\ninclude /etc/redis/laravelify.conf\n' >> /etc/redis/redis.conf
fi
systemctl restart redis-server

cat > /etc/nginx/sites-available/laravelify-phpmyadmin.conf <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${PHPMYADMIN_DOMAIN};

    root /usr/share/phpmyadmin;
    index index.php;
    # Big imports (database dumps): nginx and PHP accept up to 256 MB and run up to 10 minutes.
    client_max_body_size 256M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PANEL_PHP}-fpm.sock;
        fastcgi_read_timeout 600;
        fastcgi_param PHP_VALUE "upload_max_filesize=256M
post_max_size=256M
memory_limit=512M
max_execution_time=600";
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF
ln -sfn /etc/nginx/sites-available/laravelify-phpmyadmin.conf /etc/nginx/sites-enabled/laravelify-phpmyadmin.conf


if [[ ! -f "$APP_ROOT/.env" ]]; then
  cp "$APP_ROOT/.env.example" "$APP_ROOT/.env"
fi

if [[ ! -s /tmp/laravelify-install/setup-token ]]; then
  existing_token="$(grep -E '^LARAVELIFY_SETUP_TOKEN=' "$APP_ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
  printf '%s' "${existing_token:-$(openssl rand -hex 20)}" > /tmp/laravelify-install/setup-token
  chmod 600 /tmp/laravelify-install/setup-token
fi
SETUP_TOKEN="$(cat /tmp/laravelify-install/setup-token)"

PANEL_DOMAIN="$PANEL_DOMAIN" php <<'PHP'
<?php
$path = "/opt/laravelify/.env";
$password = file_get_contents("/tmp/laravelify-install/password");
$setupToken = trim((string) @file_get_contents("/tmp/laravelify-install/setup-token"));
$values = [
  "APP_ENV" => "production",
  "APP_DEBUG" => "false",
  "APP_URL" => "https://".getenv("PANEL_DOMAIN"),
  "DB_CONNECTION" => "mysql",
  "DB_HOST" => "127.0.0.1",
  "DB_PORT" => "3306",
  "DB_DATABASE" => "laravelify",
  "DB_USERNAME" => "root",
  "DB_PASSWORD" => $password,
  "SESSION_DRIVER" => "database",
  "QUEUE_CONNECTION" => "database",
  "CACHE_STORE" => "database",
  "REDIS_CLIENT" => "phpredis",
  "REDIS_HOST" => "127.0.0.1",
  "REDIS_PASSWORD" => "null",
  "REDIS_PORT" => "6379",
  "LARAVELIFY_HOST_COMMANDS" => "true",
  // Only the person who ran the installer can create the superadmin: /setup needs this token.
  "LARAVELIFY_SETUP_TOKEN" => $setupToken,
];
$contents = file_get_contents($path);
foreach ($values as $key => $value) {
  if ($key === "DB_PASSWORD") {
    $value = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
  }
  $line = $key."=".$value;
  if (preg_match("/^".preg_quote($key, "/")."=.*/m", $contents)) {
    $contents = preg_replace("/^".preg_quote($key, "/")."=.*/m", $line, $contents, 1);
  } else {
    $contents = rtrim($contents)."\n".$line."\n";
  }
}
file_put_contents($path, $contents);
PHP

cd "$APP_ROOT"
composer install --no-dev --optimize-autoloader --no-interaction --no-ansi
if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force --no-ansi
fi
php artisan migrate --force --no-ansi

PHPMYADMIN_DOMAIN="$PHPMYADMIN_DOMAIN" PANEL_DOMAIN="$PANEL_DOMAIN" PANEL_PHP="$PANEL_PHP" php <<'PHP'
<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$domain = getenv("PHPMYADMIN_DOMAIN");
$password = file_get_contents("/tmp/laravelify-install/password");
$php = getenv("PANEL_PHP");
foreach (["mysql", "redis", "phpmyadmin"] as $key) {
  $service = App\Models\ServerService::query()->where("key", $key)->first();
  if (! $service) {
    continue;
  }
  if ($key === "phpmyadmin") {
    $service->domain = $domain;
    $service->config = preg_replace("/server_name\s+[^;]+;/", "server_name {$domain};", $service->config, 1);
    $service->config = preg_replace("#unix:/run/php/php[0-9.]+-fpm\\.sock#", "unix:/run/php/php{$php}-fpm.sock", $service->config, 1);
    $service->settings = ["php_version" => $php];
  }
  if ($key === "mysql") {
    $service->domain = "127.0.0.1";
    $service->settings = ["bind" => "127.0.0.1", "port" => "3306", "username" => "root", "password" => $password];
  }
  if ($key === "redis") {
    $service->domain = "127.0.0.1";
    $service->settings = ["bind" => "127.0.0.1", "port" => "6379", "password" => ""];
  }
  $service->save();
}
$panel = getenv("PANEL_DOMAIN");
$phpmyadmin = getenv("PHPMYADMIN_DOMAIN");
$slug = strtolower(str_replace(".", "-", $panel));
$token = trim(file_get_contents("/tmp/laravelify-install/tunnel-token"));
$token = str_replace(["\r", "\n", "\""], "", $token);
$ingress = implode("\n", [
  "ingress:",
  "  - hostname: {$panel}",
  "    service: http://127.0.0.1:80",
  "  - hostname: {$phpmyadmin}",
  "    service: http://127.0.0.1:80",
  "  - service: http_status:404",
])."\n";
App\Models\CloudflareTunnel::query()->updateOrCreate(
  ["slug" => $slug],
  ["name" => $panel, "token" => $token, "ingress" => $ingress],
);
file_put_contents("/tmp/laravelify-install/tunnel-slug", $slug);
$unit = <<<UNIT
[Unit]
Description=Laravelify Cloudflare tunnel {$slug}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/cloudflared tunnel --no-autoupdate run --token "{$token}"
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT;
file_put_contents("/etc/systemd/system/laravelify-tunnel-{$slug}.service", $unit);
chmod("/etc/systemd/system/laravelify-tunnel-{$slug}.service", 0600);
PHP

cat > /etc/nginx/sites-available/laravelify-panel.conf <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${PANEL_DOMAIN};

    root ${APP_ROOT}/public;
    index index.php;
    # Big uploads in the file manager: nginx and PHP accept up to 256 MB.
    client_max_body_size 256M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PANEL_PHP}-fpm.sock;
        fastcgi_read_timeout 600;
        fastcgi_param PHP_VALUE "upload_max_filesize=256M
post_max_size=256M
memory_limit=512M
max_execution_time=600";
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF
ln -sfn /etc/nginx/sites-available/laravelify-panel.conf /etc/nginx/sites-enabled/laravelify-panel.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

install -d -o www-data -g www-data -m 0755 /var/www
chmod 755 "$APP_ROOT"/scripts/*.sh

# The panel's own user (laravelify) with its own PHP-FPM pool, queue worker, and scheduler;
# websites stay www-data and cannot read the panel. Also writes the sudo rule and Supervisor
# programs, and sets the owners and permissions of the panel's files.
LARAVELIFY_ROOT="$APP_ROOT" LARAVELIFY_PHP="php${PANEL_PHP}" bash "$APP_ROOT/scripts/privileges.sh"
bash "$APP_ROOT/scripts/host-apply.sh" phpmyadmin-hide all || true

if ! command -v cloudflared >/dev/null 2>&1; then
  case "$(uname -m)" in
    x86_64|amd64) CLOUDFLARED_ARCH="amd64" ;;
    aarch64|arm64) CLOUDFLARED_ARCH="arm64" ;;
    *)
      echo "cloudflared has no build for $(uname -m)." >&2
      exit 1
      ;;
  esac
  curl -fsSL -o /tmp/cloudflared.deb "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${CLOUDFLARED_ARCH}.deb"
  dpkg -i /tmp/cloudflared.deb || apt-get install -f -y
  rm -f /tmp/cloudflared.deb
fi

TUNNEL_SLUG="$(tr -d '\r\n' < /tmp/laravelify-install/tunnel-slug)"
install -d /etc/cloudflared
systemctl daemon-reload
systemctl enable --now "laravelify-tunnel-${TUNNEL_SLUG}.service"

ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

echo
echo "Laravelify is installed."
echo "Panel: https://${PANEL_DOMAIN}/setup?token=${SETUP_TOKEN}"
echo "Open that link to create the superadmin. Without the token, /setup does not open."
echo "phpMyAdmin: https://${PHPMYADMIN_DOMAIN}/  user root, and the MySQL root password you just set."
echo "In Cloudflare, send ${PANEL_DOMAIN} and ${PHPMYADMIN_DOMAIN} to http://localhost:80."
echo "Redis has no password yet. Set one in the panel under Server, Redis."
echo "Firewall allows SSH, port 80, and port 443."
