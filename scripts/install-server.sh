#!/usr/bin/env bash
# Install the host packages Laravelify manages on Ubuntu.
# MySQL 8.4 LTS, Redis, nginx, Supervisor, PM2, cloudflared, PHP-FPM 8.0-8.5, phpMyAdmin.
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script as root: sudo bash scripts/install-server.sh" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
. /etc/os-release
CODENAME="${VERSION_CODENAME}"

apt-get update
apt-get install -y ca-certificates curl gnupg lsb-release software-properties-common \
  nginx supervisor git unzip redis-server

if awk -v v="${VERSION_ID:-0}" 'BEGIN { exit !(v+0 >= 26.04) }'; then
  curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
  dpkg -i /tmp/debsuryorg-archive-keyring.deb
  rm -f /tmp/debsuryorg-archive-keyring.deb
  echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
else
  add-apt-repository -y ppa:ondrej/php
fi
apt-get update

install_php() {
  local version="$1"
  if apt-cache show "php${version}-fpm" >/dev/null 2>&1; then
    apt-get install -y \
      "php${version}-fpm" "php${version}-cli" "php${version}-mysql" "php${version}-xml" \
      "php${version}-mbstring" "php${version}-curl" "php${version}-zip" "php${version}-redis" \
      "php${version}-bcmath" "php${version}-intl"
  else
    echo "Skipping PHP ${version}: the package is not in this archive."
  fi
}

for version in 8.0 8.1 8.2 8.3 8.4 8.5; do
  install_php "${version}"
done

install -d -m 0755 /usr/share/keyrings
if [[ ! -f /usr/share/keyrings/mysql-archive-keyring.gpg ]]; then
  curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 | gpg --dearmor -o /usr/share/keyrings/mysql-archive-keyring.gpg
fi

cat > /etc/apt/sources.list.d/mysql-8.4-lts.list <<EOF
deb [signed-by=/usr/share/keyrings/mysql-archive-keyring.gpg] http://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-8.4-lts
EOF

apt-get update
apt-get install -y mysql-community-server mysql-community-client || {
  echo "MySQL 8.4 repository install failed. Falling back to the distro mysql-server package."
  apt-get install -y mysql-server
}

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
preseed_phpmyadmin
apt-get install -y phpmyadmin || echo "phpMyAdmin package was not installed. The panel nginx file still targets /usr/share/phpmyadmin."

if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi

npm install -g pm2 n
n 20 || true
n 22 || true
n 24 || true

install -d -m 0755 /usr/share/keyrings
if [[ ! -f /usr/share/keyrings/cloudflare-main.gpg ]]; then
  curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg | gpg --dearmor -o /usr/share/keyrings/cloudflare-main.gpg
fi
echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared ${CODENAME} main" > /etc/apt/sources.list.d/cloudflared.list
apt-get update
apt-get install -y cloudflared

if ! command -v composer >/dev/null 2>&1; then
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

install -d -o www-data -g www-data -m 0755 /var/www
chmod 755 "${ROOT}"/scripts/*.sh

if [[ ! -x /usr/bin/php ]]; then
  for version in 8.4 8.3 8.5 8.2 8.1 8.0; do
    if [[ -x "/usr/bin/php${version}" ]]; then
      update-alternatives --install /usr/bin/php php "/usr/bin/php${version}" 84
      break
    fi
  done
fi

echo "Laravelify host packages are installed."
if [[ -f "${ROOT}/.env" && -f "${ROOT}/vendor/autoload.php" ]]; then
  # The panel's user, PHP-FPM pool, sudo rule, and Supervisor programs (queue and scheduler).
  bash "${ROOT}/scripts/privileges.sh"
else
  echo "Next: create ${ROOT}/.env and run composer install, then: sudo bash ${ROOT}/scripts/privileges.sh"
fi
