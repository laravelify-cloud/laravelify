#!/usr/bin/env bash
# Remove a server installed by scripts/install.sh.
#   sudo bash scripts/uninstall.sh
#
# Stops the panel, deployed sites, nginx, MySQL, Redis, PHP-FPM,
# Node.js, PM2, and the Cloudflare tunnel. Then deletes /opt/laravelify,
# Laravelify sites under /var/www, and those packages.
# Type uninstall when asked. SSH is left open.

set -u

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script as root: sudo bash scripts/uninstall.sh" >&2
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

echo "This removes Laravelify and the packages the installer added:"
echo "  panel /opt/laravelify"
echo "  sites under /var/www created by Laravelify"
echo "  nginx, MySQL, Redis, PHP 8.0-8.5, phpMyAdmin"
echo "  Node.js, PM2, cloudflared, and the UFW firewall"
echo
echo "Git, curl, and your SSH login stay. MySQL databases on this machine are deleted."
echo

CONFIRM=""
if [[ "${LARAVELIFY_UNINSTALL_ASSUME_YES:-}" == "1" ]]; then
  CONFIRM="uninstall"
else
  read_tty -p "Type uninstall to continue: " CONFIRM || true
fi

if [[ "$CONFIRM" != "uninstall" ]]; then
  echo "Uninstall cancelled."
  exit 1
fi

echo "Disabling the firewall so SSH stays reachable."
ufw disable || true

stop_unit() {
  local unit="$1"
  systemctl disable --now "$unit" >/dev/null 2>&1 || true
  systemctl stop "$unit" >/dev/null 2>&1 || true
}

shopt -s nullglob

for unit in /etc/systemd/system/laravelify-tunnel-*.service /etc/systemd/system/laravelify-panel.service /etc/systemd/system/cloudflared.service; do
  stop_unit "$(basename "$unit")"
  rm -f "$unit"
done
systemctl daemon-reload || true

if command -v supervisorctl >/dev/null 2>&1; then
  while read -r program _; do
    [[ "$program" == laravelify-* ]] || continue
    supervisorctl stop "$program" || true
  done < <(supervisorctl status 2>/dev/null || true)
fi

for conf in /etc/supervisor/conf.d/laravelify-*.conf; do
  rm -f "$conf"
done
if command -v supervisorctl >/dev/null 2>&1; then
  supervisorctl reread || true
  supervisorctl update || true
fi

for conf in /etc/nginx/sites-available/laravelify-*.conf; do
  name="$(basename "$conf" .conf)"
  slug="${name#laravelify-}"
  rm -f "/etc/nginx/sites-enabled/${name}.conf"
  rm -f "$conf"
  if [[ "$slug" != "panel" && "$slug" != "phpmyadmin" && -d "/var/www/${slug}" ]]; then
    echo "Removing site /var/www/${slug}"
    rm -rf "/var/www/${slug}"
  fi
done

if command -v nginx >/dev/null 2>&1; then
  nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
fi

rm -f /etc/sudoers.d/laravelify
rm -f /etc/mysql/mysql.conf.d/laravelify.cnf
rm -f /etc/redis/laravelify.conf
if [[ -f /etc/redis/redis.conf ]]; then
  sed -i '\|/etc/redis/laravelify.conf|d' /etc/redis/redis.conf || true
fi
rm -rf /etc/cloudflared /opt/laravelify /usr/local/n /usr/local/bin/composer
rm -f /usr/local/bin/node /usr/local/bin/npm /usr/local/bin/npx /usr/local/bin/pm2 /usr/local/bin/n

if command -v pm2 >/dev/null 2>&1; then
  pm2 kill || true
  pm2 unstartup systemd || true
fi
rm -rf /root/.pm2

echo "Removing apt repositories added for PHP, MySQL, and Node.js."
for source in /etc/apt/sources.list.d/*; do
  [[ -f "$source" ]] || continue
  if grep -Eq 'packages\.sury\.org/php|ppa\.launchpadcontent\.net/ondrej/php|repo\.mysql\.com|deb\.nodesource\.com|pkg\.cloudflare\.com/cloudflared' "$source"; then
    rm -f "$source"
  fi
done
rm -f /usr/share/keyrings/mysql-archive-keyring.gpg /usr/share/keyrings/debsuryorg-archive-keyring.gpg

apt-get update || true

echo "Removing packages."
apt-get purge -y \
  nginx nginx-common \
  supervisor \
  redis-server redis-tools \
  phpmyadmin \
  mysql-community-server mysql-community-client mysql-community-server-core mysql-community-client-core \
  mysql-community-client-plugins mysql-server mysql-client mysql-common \
  nodejs \
  cloudflared \
  debsuryorg-archive-keyring \
  ufw \
  'php8.0-*' 'php8.1-*' 'php8.2-*' 'php8.3-*' 'php8.4-*' 'php8.5-*' 'php-*' \
  || true

apt-get autoremove -y --purge || true

echo
echo "Laravelify has been uninstalled."
echo "SSH is still available. The firewall is off."
