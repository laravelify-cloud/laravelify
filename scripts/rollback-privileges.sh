#!/usr/bin/env bash
# Undo scripts/privileges.sh: the panel runs as www-data again, like 0.3.0-beta.39 and older.
#
#   sudo bash /opt/laravelify/scripts/rollback-privileges.sh
#
# This opens the hole privileges.sh closes (website code can read the panel's .env and reach
# root), so use it only to get a broken server running, then put the older release back:
#
#   curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo LARAVELIFY_VERSION=0.3.0-beta.39 bash
#
# privileges.sh does nothing while /var/lib/laravelify/.no-separation exists. Delete that file
# and run `sudo bash /opt/laravelify/scripts/privileges.sh` to separate again.
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  exec sudo bash "$0" "$@"
fi

APP_ROOT="${LARAVELIFY_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STATE_DIR=/var/lib/laravelify
ROLLBACK_DIR="${STATE_DIR}/rollback/before-privilege-separation"
SITE_USER=www-data
PATH_SAFE=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

say() {
  echo "[rollback] $*"
}

[[ -f "${ROLLBACK_DIR}/.done" ]] || { echo "Nothing to roll back: ${ROLLBACK_DIR} does not exist." >&2; exit 1; }

saved() {
  echo "${ROLLBACK_DIR}/$(echo "${1#/}" | tr '/' '_')"
}

restore() {
  local file="$1"
  if [[ -f "$(saved "${file}")" ]]; then
    cp -p -- "$(saved "${file}")" "${file}"
    return 0
  fi
  return 1
}

# Websites with their own user go back to www-data first, while the panel still runs as its own
# user (it rewrites their workers and nginx files).
if getent passwd | cut -d: -f1 | grep -q '^lfy-'; then
  say "Giving every website back to www-data first"
  bash "${APP_ROOT}/scripts/rollback-site-users.sh" || { echo "Some websites still have their own user; fix that first (see above)." >&2; exit 1; }
fi

touch "${STATE_DIR}/.no-separation"
rm -f "${STATE_DIR}/.sites-separated"

say "Stopping the panel's queue worker and scheduler"
supervisorctl stop laravelify-queue 'laravelify-deploys:*' laravelify-scheduler >/dev/null 2>&1 || true

# nginx: the files from before, or at least the sites' pool again and www-data workers.
NGINX_BACKUP="$(mktemp -d)"
cp -p /etc/nginx/nginx.conf "${NGINX_BACKUP}/" 2>/dev/null || true
restore /etc/nginx/nginx.conf || sed -i -E '0,/^[[:space:]]*user[[:space:]]+[^;]+;/s//user www-data;/' /etc/nginx/nginx.conf
PANEL_PHP="$(grep -oE 'unix:/run/php/php[0-9]+\.[0-9]+-fpm\.sock' "$(saved /etc/nginx/sites-available/laravelify-panel.conf)" 2>/dev/null | head -n1 | grep -oE '[0-9]+\.[0-9]+' || echo 8.4)"
for file in /etc/nginx/sites-available/laravelify-panel.conf /etc/nginx/sites-available/laravelify-phpmyadmin.conf; do
  if [[ -f "${file}" ]]; then
    sed -i "s#unix:/run/php/laravelify\.sock#unix:/run/php/php${PANEL_PHP}-fpm.sock#g" "${file}"
  fi
done
for dir in /var/lib/nginx /var/cache/nginx; do
  if [[ -d "${dir}" ]] && id -u laravelify-web >/dev/null 2>&1; then
    find "${dir}" -xdev -user laravelify-web -exec chown -h "${SITE_USER}" {} + 2>/dev/null || true
  fi
done

# The panel's files belong to www-data again.
say "Giving ${APP_ROOT}/storage and bootstrap/cache back to ${SITE_USER}"
chmod -R u=rwX,go=rX "${APP_ROOT}"
chown -R -P "${SITE_USER}:${SITE_USER}" "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"
chown root:root "${APP_ROOT}/.env"
chmod 0644 "${APP_ROOT}/.env"
for key in SESSION_DRIVER QUEUE_CONNECTION CACHE_STORE; do
  before="$(grep -m1 "^${key}=" "$(saved "${APP_ROOT}/.env")" 2>/dev/null | cut -d= -f2- || true)"
  if [[ -n "${before}" ]]; then
    sed -i "s/^${key}=.*/${key}=${before}/" "${APP_ROOT}/.env"
  fi
done
for file in /etc/phpmyadmin/config-db.php /var/lib/phpmyadmin/blowfish_secret.inc.php /var/lib/phpmyadmin/config.inc.php /var/lib/phpmyadmin; do
  if [[ -e "${file}" && "$(stat -c '%G' -- "${file}")" == laravelify ]]; then
    chgrp "${SITE_USER}" -- "${file}"
  fi
done
if [[ -d /var/lib/phpmyadmin/tmp ]]; then
  chown -R -P "${SITE_USER}:${SITE_USER}" /var/lib/phpmyadmin/tmp
fi
if [[ -d "${STATE_DIR}/trash" ]]; then
  install -d -o "${SITE_USER}" -g "${SITE_USER}" "${APP_ROOT}/storage/app/trash"
  for item in "${STATE_DIR}/trash"/*; do
    [[ -e "${item}" || -L "${item}" ]] || continue
    if [[ ! -e "${APP_ROOT}/storage/app/trash/$(basename "${item}")" ]]; then
      mv -- "${item}" "${APP_ROOT}/storage/app/trash/"
    fi
  done
fi

# PHP-FPM: without the panel pool, the panel runs in the sites' pool again.
nginx -t || { cp -p "${NGINX_BACKUP}/nginx.conf" /etc/nginx/nginx.conf; echo "nginx rejected the old config; check it with nginx -t" >&2; exit 1; }
rm -f /etc/php/*/fpm/pool.d/laravelify.conf
systemctl reload "php${PANEL_PHP}-fpm" || true
systemctl reload nginx
rm -rf -- "${NGINX_BACKUP}"

# sudo and Supervisor as before.
if restore /etc/sudoers.d/laravelify; then
  visudo -cf /etc/sudoers.d/laravelify >/dev/null || echo "warning: /etc/sudoers.d/laravelify is invalid; check it with visudo -cf" >&2
fi
restore /etc/supervisor/conf.d/laravelify-panel.conf || sed -i 's/^user=laravelify$/user=www-data/' /etc/supervisor/conf.d/laravelify-panel.conf
restore /etc/supervisor/conf.d/laravelify-scheduler.conf || true
supervisorctl reread >/dev/null || true
supervisorctl update || true
supervisorctl start laravelify-queue 'laravelify-deploys:*' laravelify-scheduler >/dev/null 2>&1 || true

# Node apps go back to root's PM2 daemon, from the files older versions read.
if command -v pm2 >/dev/null 2>&1; then
  for config in "${STATE_DIR}/pm2"/*.config.cjs; do
    [[ -f "${config}" ]] || continue
    name="$(basename "${config}" .config.cjs)"
    [[ -d "/var/www/${name}" ]] || continue
    runuser -u "${SITE_USER}" -- env -i PATH="${PATH_SAFE}" PM2_HOME="${STATE_DIR}/home/.pm2" pm2 delete "${name}" >/dev/null 2>&1 || true
    runuser -u "${SITE_USER}" -- /bin/sh -c 'cat > "$1"' sh "/var/www/${name}/ecosystem.config.cjs" < "${config}"
    if [[ -f "/var/www/${name}/.next/standalone/server.js" || -f "/var/www/${name}/.laravelify-built" ]] && [[ ! -f "/var/www/${name}/.laravelify-disabled" ]]; then
      say "Starting ${name} in root's PM2"
      HOME=/root PM2_HOME=/root/.pm2 pm2 startOrReload "/var/www/${name}/ecosystem.config.cjs" --update-env >/dev/null 2>&1 || echo "warning: could not start ${name}" >&2
    fi
  done
  HOME=/root PM2_HOME=/root/.pm2 pm2 save --force >/dev/null 2>&1 || true
  runuser -u "${SITE_USER}" -- env -i PATH="${PATH_SAFE}" PM2_HOME="${STATE_DIR}/home/.pm2" pm2 save --force >/dev/null 2>&1 || true
  systemctl disable --now "pm2-${SITE_USER}" >/dev/null 2>&1 || true
fi

say "Done. The panel runs as ${SITE_USER} again. Now put the older release back:"
say "  curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo LARAVELIFY_VERSION=0.3.0-beta.39 bash"
