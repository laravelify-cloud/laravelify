#!/usr/bin/env bash
# Give the panel its own Unix user, so website code cannot reach the panel or root.
#
#   sudo bash /opt/laravelify/scripts/privileges.sh
#
# Run by install.sh and update.sh. Every step checks what is there first, so running it again
# changes nothing. The state before the first run is kept in
# /var/lib/laravelify/rollback/before-privilege-separation (see scripts/rollback-privileges.sh).
#
# After it:
#   laravelify       the panel: its PHP-FPM pool (/run/php/laravelify.sock, also phpMyAdmin),
#                    the queue worker, and the scheduler. Owns storage/ and bootstrap/cache.
#                    In the www-data group, so it can read site files.
#   lfy-<slug>       one user per website: its PHP-FPM pool (/run/php/site-<slug>.sock), Supervisor
#                    workers, PM2 daemon, deploy commands, and terminal. Owns /var/www/<slug>
#                    (group laravelify-sites, setgid). Cannot read other sites or the panel, has no
#                    sudo. New sites get one; existing sites move with LARAVELIFY_SITE_USERS=1 or
#                    `artisan laravelify:separate-sites --site=<slug>` (see rollback-site-users.sh).
#   www-data         websites not moved to their own user yet (PHP-FPM www pools, Supervisor
#                    workers, PM2 apps), their deploy commands and terminal. Cannot read
#                    /opt/laravelify/.env or storage, has no sudo.
#   laravelify-web   nginx's worker processes (unless nginx already had its own user). The only
#                    user besides the panel that may open the panel's PHP-FPM socket.
#   root             nginx's master, Supervisor, host-apply.sh (which the panel may run with sudo).
#
# Groups: laravelify-sites (the panel and nginx: they read site folders through it; never a site
# user) and laravelify-tenants (every site's own user: the panel may sudo to them).
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  exec sudo bash "$0" "$@"
fi

APP_ROOT="${LARAVELIFY_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
# host-apply.sh pm2-boot starts this script on servers without the panel user; not from here.
export LARAVELIFY_PRIVILEGES=1
PANEL_USER=laravelify
SITE_USER=www-data
WEB_USER_NEW=laravelify-web
READ_GROUP=laravelify-sites
TENANT_GROUP=laravelify-tenants
STATE_DIR=/var/lib/laravelify
SITE_HOME="${STATE_DIR}/home"
PANEL_SOCKET=/run/php/laravelify.sock
PANEL_NGINX=/etc/nginx/sites-available/laravelify-panel.conf
PMA_NGINX=/etc/nginx/sites-available/laravelify-phpmyadmin.conf
NGINX_CONF=/etc/nginx/nginx.conf
SUPERVISOR_PANEL=/etc/supervisor/conf.d/laravelify-panel.conf
SUPERVISOR_SCHEDULER=/etc/supervisor/conf.d/laravelify-scheduler.conf
SUDOERS=/etc/sudoers.d/laravelify
ROLLBACK_DIR="${STATE_DIR}/rollback/before-privilege-separation"
PATH_SAFE=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

say() {
  echo "[privileges] $*"
}

fail() {
  echo "[privileges] error: $*" >&2
  exit 1
}

[[ -f "${APP_ROOT}/artisan" && -f "${APP_ROOT}/.env" ]] || fail "no panel at ${APP_ROOT}"

# scripts/rollback-privileges.sh was run: keep the panel as www-data (and the files it writes
# its own) until that file is deleted.
if [[ -f "${STATE_DIR}/.no-separation" ]]; then
  say "Skipped: ${STATE_DIR}/.no-separation exists (rolled back). Delete it and run this again to separate."
  chown -R -P "${SITE_USER}:${SITE_USER}" "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"
  exit 0
fi

# The PHP version the panel runs on: the pool made earlier, the panel's nginx file, or the newest
# installed PHP-FPM (8.4 first, the version the panel is built for).
panel_php() {
  local version file
  if [[ -n "${LARAVELIFY_PHP:-}" ]]; then
    echo "${LARAVELIFY_PHP#php}"
    return
  fi
  for file in /etc/php/*/fpm/pool.d/laravelify.conf; do
    if [[ -f "${file}" ]]; then
      version="${file#/etc/php/}"
      echo "${version%%/*}"
      return
    fi
  done
  version="$(grep -oE 'unix:/run/php/php[0-9]+\.[0-9]+-fpm\.sock' "${PANEL_NGINX}" 2>/dev/null | head -n1 | grep -oE '[0-9]+\.[0-9]+' || true)"
  if [[ -n "${version}" ]]; then
    echo "${version}"
    return
  fi
  for version in 8.4 8.3 8.5 8.2 8.1 8.0; do
    if [[ -x "/usr/sbin/php-fpm${version}" ]]; then
      echo "${version}"
      return
    fi
  done
  fail "no PHP-FPM is installed"
}

# Nothing is changed while nginx's config is already broken: the switch below must be testable.
nginx -t >/dev/null 2>&1 || fail "nginx -t fails before any change. Fix nginx first (run: nginx -t)."

PANEL_PHP="$(panel_php)"
PHP_BIN="/usr/bin/php${PANEL_PHP}"
[[ -x "${PHP_BIN}" ]] || PHP_BIN="$(command -v php)"

as_panel() {
  runuser -u "${PANEL_USER}" -- env -i PATH="${PATH_SAFE}" HOME="${APP_ROOT}/storage/app/process-home" LANG=C.UTF-8 "$@"
}

env_value() {
  grep -m1 "^$1=" "${APP_ROOT}/.env" 2>/dev/null | cut -d= -f2- | tr -d '"\r' || true
}

# --- 1. Keep the state from before the first run, for rollback-privileges.sh -------------

if [[ ! -f "${ROLLBACK_DIR}/.done" ]]; then
  say "Saving the current setup to ${ROLLBACK_DIR}"
  install -d -o root -g root -m 0700 "${STATE_DIR}/rollback" "${ROLLBACK_DIR}"
  for file in "${SUDOERS}" "${SUPERVISOR_PANEL}" "${SUPERVISOR_SCHEDULER}" "${PANEL_NGINX}" "${PMA_NGINX}" "${NGINX_CONF}" /root/.pm2/dump.pm2 "${APP_ROOT}/.env"; do
    if [[ -f "${file}" ]]; then
      cp -p -- "${file}" "${ROLLBACK_DIR}/$(echo "${file#/}" | tr '/' '_')"
    fi
  done
  stat -c '%U:%G %a %n' "${APP_ROOT}" "${APP_ROOT}/.env" "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache" > "${ROLLBACK_DIR}/ownership.txt" 2>/dev/null || true
  touch "${ROLLBACK_DIR}/.done"
fi

# --- 2. Users and groups -----------------------------------------------------------------

if ! getent group "${PANEL_USER}" >/dev/null; then
  groupadd --system "${PANEL_USER}"
fi
if ! id -u "${PANEL_USER}" >/dev/null 2>&1; then
  say "Creating the ${PANEL_USER} user"
  useradd --system --gid "${PANEL_USER}" --home-dir "${STATE_DIR}" --no-create-home \
    --shell /usr/sbin/nologin --comment "Laravelify panel" "${PANEL_USER}"
fi
if ! id -nG "${PANEL_USER}" | tr ' ' '\n' | grep -qx "${SITE_USER}"; then
  usermod -aG "${SITE_USER}" "${PANEL_USER}"
fi

# nginx's workers must not be www-data: anything nginx can open, website code could open too,
# and FastCGI to the panel's pool runs any PHP file as the panel. They get their own user, in
# the www-data group (site files, the sites' PHP-FPM sockets, Basic Auth files).
nginx_user() {
  awk '/^[[:space:]]*user[[:space:]]+/ { gsub(/;/, "", $2); print $2; exit }' "${NGINX_CONF}" 2>/dev/null
}

WEB_USER="$(nginx_user || true)"
if [[ -z "${WEB_USER}" || "${WEB_USER}" == "${SITE_USER}" || "${WEB_USER}" == root ]]; then
  WEB_USER="${WEB_USER_NEW}"
  if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
    say "Creating the ${WEB_USER} user for nginx"
    useradd --system --user-group --home-dir /var/lib/nginx --no-create-home \
      --shell /usr/sbin/nologin --comment "Laravelify nginx workers" "${WEB_USER}"
  fi
fi
id -u "${WEB_USER}" >/dev/null 2>&1 || fail "nginx runs as ${WEB_USER}, which does not exist"
if ! id -nG "${WEB_USER}" | tr ' ' '\n' | grep -qx "${SITE_USER}"; then
  usermod -aG "${SITE_USER}" "${WEB_USER}"
fi
WEB_GROUP="$(id -gn "${WEB_USER}")"

# One user per website: the panel and nginx read site folders through laravelify-sites, and the
# panel may sudo to the members of laravelify-tenants (the sites' own users, nobody else).
GROUPS_CHANGED=0
if [[ ! -f "${STATE_DIR}/.no-site-users" ]]; then
  for group in "${READ_GROUP}" "${TENANT_GROUP}"; do
    getent group "${group}" >/dev/null || groupadd --system "${group}"
  done
  for user in "${PANEL_USER}" "${WEB_USER}"; do
    if ! id -nG "${user}" | tr ' ' '\n' | grep -qx "${READ_GROUP}"; then
      usermod -aG "${READ_GROUP}" "${user}"
      GROUPS_CHANGED=1
    fi
  done
  # Nobody but the sites' own users is in laravelify-tenants: sudo to it is sudo to them.
  for user in $(getent group "${TENANT_GROUP}" | cut -d: -f4 | tr ',' ' '); do
    [[ "${user}" == lfy-* ]] || fail "${user} must not be in ${TENANT_GROUP} (gpasswd -d ${user} ${TENANT_GROUP})"
  done
fi

# --- 3. Folders --------------------------------------------------------------------------

install -d -o root -g root -m 0755 "${STATE_DIR}" "${STATE_DIR}/pm2"
install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0750 "${SITE_HOME}" "${STATE_DIR}/trash"
install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0750 "${SITE_HOME}/.composer" "${SITE_HOME}/.npm" "${SITE_HOME}/.corepack" "${SITE_HOME}/checkouts"
install -d -o root -g "${PANEL_USER}" -m 0750 /var/log/laravelify /var/log/laravelify/sites
# /var/www is root's once websites have their own users: the owner of /var/www could rename any
# site's folder and put its own in its place.
if [[ -f "${STATE_DIR}/.no-site-users" ]]; then
  install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0755 /var/www
else
  install -d -o root -g root -m 0755 /var/www "${STATE_DIR}/sites"
  # Sites still on www-data keep HOME=/var/www for their workers: their tool folders stay theirs.
  for dir in .cache .config .local .npm; do
    if [[ ! -e "/var/www/${dir}" ]]; then
      install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0700 "/var/www/${dir}"
    fi
  done
fi

# The file manager's trash moves out of the panel's storage: the site user moves files in and out.
if [[ -d "${APP_ROOT}/storage/app/trash" ]]; then
  for item in "${APP_ROOT}/storage/app/trash"/*; do
    [[ -e "${item}" || -L "${item}" ]] || continue
    name="$(basename "${item}")"
    if [[ ! -e "${STATE_DIR}/trash/${name}" ]]; then
      mv -- "${item}" "${STATE_DIR}/trash/${name}"
    fi
  done
  rmdir "${APP_ROOT}/storage/app/trash" 2>/dev/null || true
  chown -R -P -h "${SITE_USER}:${SITE_USER}" "${STATE_DIR}/trash"
fi

# --- 4. The panel stops using Redis -------------------------------------------------------
# Redis has no password by default and every website can reach it. Sessions, the queue, and the
# cache in Redis would let a website forge a panel login or queue a job that runs as the panel.

SESSIONS_WERE_REDIS=0
[[ "$(env_value SESSION_DRIVER)" == redis ]] && SESSIONS_WERE_REDIS=1

# On the first run the worker is still www-data with the old code: let a running deploy finish
# (queue:restart waits for the current job), then stop the panel's programs before their files
# change owner.
if grep -qs '^user=www-data' "${SUPERVISOR_PANEL}" "${SUPERVISOR_SCHEDULER}"; then
  if supervisorctl status laravelify-queue 2>/dev/null | grep -q RUNNING; then
    old_pid="$(supervisorctl pid laravelify-queue 2>/dev/null || echo 0)"
    say "Waiting for the panel's queue worker to finish its current job (up to 15 minutes)..."
    runuser -u "${SITE_USER}" -- "${PHP_BIN}" "${APP_ROOT}/artisan" queue:restart --no-ansi >/dev/null 2>&1 || true
    for _ in $(seq 1 900); do
      new_pid="$(supervisorctl pid laravelify-queue 2>/dev/null || echo 0)"
      [[ "${new_pid}" != "${old_pid}" ]] && break
      sleep 1
    done
  fi
  supervisorctl stop laravelify-queue laravelify-scheduler >/dev/null 2>&1 || true
fi

for key in SESSION_DRIVER QUEUE_CONNECTION CACHE_STORE; do
  if [[ "$(env_value "${key}")" == redis ]]; then
    say "${key}: redis -> database"
    sed -i "s/^${key}=.*/${key}=database/" "${APP_ROOT}/.env"
  fi
done
# Panel sign-ins last 2 days now; a server still on the old 2-hour default moves to it.
if [[ "$(env_value SESSION_LIFETIME)" == 120 ]]; then
  say "SESSION_LIFETIME: 120 -> 2880 minutes (2 days)"
  sed -i "s/^SESSION_LIFETIME=.*/SESSION_LIFETIME=2880/" "${APP_ROOT}/.env"
fi
if [[ "${SESSIONS_WERE_REDIS}" == 1 ]]; then
  say "Panel sessions moved to the database: everyone signs in to the panel again once."
fi

# --- 5. The panel's PHP-FPM pool ---------------------------------------------------------

POOL_DIR="/etc/php/${PANEL_PHP}/fpm/pool.d"
POOL_FILE="${POOL_DIR}/laravelify.conf"
[[ -d "${POOL_DIR}" ]] || fail "PHP-FPM ${PANEL_PHP} is not installed (${POOL_DIR})"

# A pool left from another PHP version (the panel moved to a newer PHP) goes away.
for file in /etc/php/*/fpm/pool.d/laravelify.conf; do
  if [[ -f "${file}" && "${file}" != "${POOL_FILE}" ]]; then
    old_version="${file#/etc/php/}"
    old_version="${old_version%%/*}"
    rm -f -- "${file}"
    systemctl reload "php${old_version}-fpm" >/dev/null 2>&1 || true
  fi
done

POOL_TMP="$(mktemp)"
trap 'rm -f -- "${POOL_TMP}"' EXIT
cat > "${POOL_TMP}" <<POOL
; Written by Laravelify (scripts/privileges.sh). Run it again to restore this file.
; The panel and phpMyAdmin run here as ${PANEL_USER}. Only the panel and nginx's user may open
; the socket: website code (${SITE_USER}) must never talk FastCGI to this pool.
[laravelify]
user = ${PANEL_USER}
group = ${PANEL_USER}
listen = ${PANEL_SOCKET}
listen.owner = ${PANEL_USER}
listen.group = ${WEB_GROUP}
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 500
request_terminate_timeout = 900
POOL

if ! cmp -s "${POOL_TMP}" "${POOL_FILE}"; then
  say "Writing the panel's PHP-FPM pool (${POOL_FILE})"
  install -m 0644 "${POOL_TMP}" "${POOL_FILE}"
  "/usr/sbin/php-fpm${PANEL_PHP}" -t >/dev/null 2>&1 || { rm -f -- "${POOL_FILE}"; fail "php-fpm${PANEL_PHP} rejected the panel pool"; }
  systemctl reload "php${PANEL_PHP}-fpm"
fi
for _ in $(seq 1 40); do
  [[ -S "${PANEL_SOCKET}" ]] && break
  sleep 0.25
done
[[ -S "${PANEL_SOCKET}" ]] || fail "the panel's PHP-FPM socket ${PANEL_SOCKET} did not appear"

# --- 6. Files of the panel ---------------------------------------------------------------
# Code: root-owned, readable by the panel only (public/ and scripts/ also by nginx and the
# site user, which runs scripts/with-node.sh). .env: root, readable by the panel group.
# storage/ and bootstrap/cache: the panel's. Nothing for others.

say "Setting owners and permissions in ${APP_ROOT}"
chown -R -P root:"${PANEL_USER}" "${APP_ROOT}"
chmod -R u=rwX,g=rX,o= "${APP_ROOT}"
chmod 0751 "${APP_ROOT}"
chmod -R o+rX "${APP_ROOT}/public" "${APP_ROOT}/scripts"
chmod 0755 "${APP_ROOT}"/scripts/*.sh
chown root:"${PANEL_USER}" "${APP_ROOT}/.env"
chmod 0640 "${APP_ROOT}/.env"
install -d -o "${PANEL_USER}" -g "${PANEL_USER}" -m 0750 \
  "${APP_ROOT}/storage/app/process-home" \
  "${APP_ROOT}/storage/logs" \
  "${APP_ROOT}/storage/framework/cache" \
  "${APP_ROOT}/storage/framework/sessions" \
  "${APP_ROOT}/storage/framework/views" \
  "${APP_ROOT}/bootstrap/cache"
chown -R -P "${PANEL_USER}:${PANEL_USER}" "${APP_ROOT}/storage" "${APP_ROOT}/bootstrap/cache"

# phpMyAdmin now runs as the panel user: its config and temp files follow it, and stay out of
# reach of website code (they hold the control user's password and the cookie secret).
for file in /etc/phpmyadmin/config-db.php /var/lib/phpmyadmin/blowfish_secret.inc.php /var/lib/phpmyadmin/config.inc.php /var/lib/phpmyadmin; do
  if [[ -e "${file}" && "$(stat -c '%G' -- "${file}")" == "${SITE_USER}" ]]; then
    chgrp "${PANEL_USER}" -- "${file}"
  fi
done
if [[ -d /var/lib/phpmyadmin/tmp && "$(stat -c '%U' /var/lib/phpmyadmin/tmp)" == "${SITE_USER}" ]]; then
  chown -R -P "${PANEL_USER}:${PANEL_USER}" /var/lib/phpmyadmin/tmp
fi

# --- 7. nginx ----------------------------------------------------------------------------

NGINX_BACKUP="$(mktemp -d)"
for file in "${NGINX_CONF}" "${PANEL_NGINX}" "${PMA_NGINX}"; do
  [[ -f "${file}" ]] && cp -p -- "${file}" "${NGINX_BACKUP}/$(basename "${file}")"
done

restore_nginx() {
  local file
  for file in "${NGINX_CONF}" "${PANEL_NGINX}" "${PMA_NGINX}"; do
    if [[ -f "${NGINX_BACKUP}/$(basename "${file}")" ]]; then
      cp -p -- "${NGINX_BACKUP}/$(basename "${file}")" "${file}"
    fi
  done
}

if [[ "$(nginx_user || true)" != "${WEB_USER}" ]]; then
  say "nginx workers: ${WEB_USER}"
  if grep -qE '^[[:space:]]*user[[:space:]]+' "${NGINX_CONF}"; then
    sed -i -E "0,/^[[:space:]]*user[[:space:]]+[^;]+;/s//user ${WEB_USER};/" "${NGINX_CONF}"
  else
    sed -i "1i user ${WEB_USER};" "${NGINX_CONF}"
  fi
fi
# nginx's own temp and cache folders belonged to www-data.
for dir in /var/lib/nginx /var/cache/nginx; do
  if [[ -d "${dir}" ]]; then
    find "${dir}" -xdev -user "${SITE_USER}" -exec chown -h "${WEB_USER}" {} + 2>/dev/null || true
  fi
done

for file in "${PANEL_NGINX}" "${PMA_NGINX}"; do
  if [[ -f "${file}" ]] && grep -qE 'unix:/run/php/php[0-9.]+-fpm\.sock' "${file}"; then
    sed -i -E "s#unix:/run/php/php[0-9.]+-fpm\.sock#unix:${PANEL_SOCKET}#g" "${file}"
  fi
done

if ! nginx -t >/dev/null 2>&1; then
  restore_nginx
  nginx -t || true
  fail "nginx rejected the new config; the old one was put back"
fi
systemctl reload nginx
rm -rf -- "${NGINX_BACKUP}"

# --- 8. sudo -----------------------------------------------------------------------------

SUDO_TMP="$(mktemp)"
cat > "${SUDO_TMP}" <<SUDO
# Written by Laravelify (scripts/privileges.sh).
# The panel may run its host script as root, and commands as the site users (deploys and file
# changes in site folders): www-data and every website's own user (the members of
# ${TENANT_GROUP}). Website code has no sudo at all.
${PANEL_USER} ALL=(root) NOPASSWD: ${APP_ROOT}/scripts/host-apply.sh
${PANEL_USER} ALL=(${SITE_USER}, %${TENANT_GROUP}) NOPASSWD: ALL
Defaults>${SITE_USER} !syslog
Defaults>%${TENANT_GROUP} !syslog
SUDO
if ! cmp -s "${SUDO_TMP}" "${SUDOERS}"; then
  visudo -cf "${SUDO_TMP}" >/dev/null || { rm -f -- "${SUDO_TMP}"; fail "the new sudoers file is invalid"; }
  install -m 0440 -o root -g root "${SUDO_TMP}" "${SUDOERS}"
  say "sudo: ${PANEL_USER} may run host-apply.sh and commands as the site users; sites have no sudo"
fi
rm -f -- "${SUDO_TMP}"

# --- 9. The panel's queue worker and scheduler -------------------------------------------

SUPERVISOR_TMP="$(mktemp)"
cat > "${SUPERVISOR_TMP}" <<CONF
; Written by Laravelify (scripts/privileges.sh). The panel's own programs, as ${PANEL_USER}.
[program:laravelify-queue]
command=${PHP_BIN} ${APP_ROOT}/artisan queue:work --sleep=1 --tries=1 --timeout=3600
directory=${APP_ROOT}
autostart=true
autorestart=true
; Keep trying while MySQL restarts (an update, a reboot): never give up after 3 quick exits.
startretries=1000
stopasgroup=true
killasgroup=true
stopwaitsecs=600
user=${PANEL_USER}
environment=HOME="${APP_ROOT}/storage/app/process-home",COMPOSER_HOME="${APP_ROOT}/storage/app/process-home/.composer"
redirect_stderr=true
stdout_logfile=/var/log/laravelify/panel-queue.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3

[program:laravelify-scheduler]
command=${PHP_BIN} ${APP_ROOT}/artisan schedule:work
directory=${APP_ROOT}
autostart=true
autorestart=true
; Keep trying while MySQL restarts (an update, a reboot): never give up after 3 quick exits.
startretries=1000
stopasgroup=true
killasgroup=true
user=${PANEL_USER}
environment=HOME="${APP_ROOT}/storage/app/process-home"
redirect_stderr=true
stdout_logfile=/var/log/laravelify/panel-scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
CONF
if ! cmp -s "${SUPERVISOR_TMP}" "${SUPERVISOR_PANEL}" || [[ -f "${SUPERVISOR_SCHEDULER}" ]]; then
  say "Panel queue worker and scheduler: ${PANEL_USER}"
  install -m 0644 "${SUPERVISOR_TMP}" "${SUPERVISOR_PANEL}"
  rm -f -- "${SUPERVISOR_SCHEDULER}"
  supervisorctl reread >/dev/null || true
  supervisorctl update || true
fi
rm -f -- "${SUPERVISOR_TMP}"

# --- 10. Websites ------------------------------------------------------------------------

# Files root left in site folders (Node apps ran as root before) go back to the site user.
for nginx_file in /etc/nginx/sites-available/laravelify-*.conf; do
  [[ -f "${nginx_file}" ]] || continue
  slug="$(basename "${nginx_file}" .conf)"
  slug="${slug#laravelify-}"
  [[ "${slug}" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] || continue
  [[ "${slug}" == panel || "${slug}" == phpmyadmin ]] && continue
  site_dir="/var/www/${slug}"
  # Only folders of sites still on www-data (a site with its own user is never touched here).
  if [[ -d "${site_dir}" && ! -L "${site_dir}" && "$(stat -c '%U' -- "${site_dir}")" == "${SITE_USER}" ]]; then
    find "${site_dir}" -xdev -user root -exec chown -h "${SITE_USER}:${SITE_USER}" {} + 2>/dev/null || true
  fi
done

# PM2: the site user's daemon, restored on boot by pm2-www-data.
if command -v pm2 >/dev/null 2>&1; then
  install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0750 "${SITE_HOME}/.pm2"
  if ! systemctl is-enabled --quiet "pm2-${SITE_USER}" 2>/dev/null; then
    env PATH="${PATH_SAFE}" pm2 startup systemd -u "${SITE_USER}" --hp "${SITE_HOME}" >/dev/null 2>&1 \
      || say "warning: could not enable pm2-${SITE_USER}"
  fi
fi

# The saved phpMyAdmin config uses the panel's pool too, then every site's workers are applied
# again: Supervisor programs as www-data with logs in /var/log/laravelify/sites, Node apps moved
# from root's PM2 to the site user's.
as_panel "${PHP_BIN}" "${APP_ROOT}/artisan" config:clear --no-ansi >/dev/null || true
as_panel "${PHP_BIN}" "${APP_ROOT}/artisan" laravelify:adopt-panel-user --no-ansi || say "warning: could not update the saved phpMyAdmin config or backup folders"
# Once (or with LARAVELIFY_REAPPLY=1): it restarts every worker and Node app.
if [[ ! -f "${STATE_DIR}/.sites-separated" || "${LARAVELIFY_REAPPLY:-}" == 1 ]]; then
  if as_panel "${PHP_BIN}" "${APP_ROOT}/artisan" laravelify:reapply-processes --no-ansi; then
    touch "${STATE_DIR}/.sites-separated"
  else
    say "warning: some sites were not applied again (see above). They keep running as before; fix them, then run: sudo LARAVELIFY_REAPPLY=1 bash ${APP_ROOT}/scripts/privileges.sh"
  fi
fi
bash "${APP_ROOT}/scripts/host-apply.sh" pm2-boot all || say "warning: PM2 boot setup failed"

supervisorctl start laravelify-queue laravelify-scheduler >/dev/null 2>&1 || true

# --- 11. One user per website --------------------------------------------------------------
# A process gets new groups only when it starts: the panel's pool, its queue worker (after its
# current job), and nginx's workers start again once, so they can read laravelify-sites folders.

if [[ "${GROUPS_CHANGED}" == 1 ]]; then
  say "The panel and nginx joined ${READ_GROUP}: restarting them once"
  systemctl reload "php${PANEL_PHP}-fpm" || true
  systemctl reload nginx || true
  as_panel "${PHP_BIN}" "${APP_ROOT}/artisan" queue:restart --no-ansi >/dev/null 2>&1 || true
  supervisorctl restart laravelify-scheduler >/dev/null 2>&1 || true
fi

if [[ -f "${STATE_DIR}/.no-site-users" ]]; then
  say "Websites keep www-data: ${STATE_DIR}/.no-site-users exists (rolled back)."
elif [[ "${LARAVELIFY_SITE_USERS:-}" == 1 ]]; then
  say "Moving every website to its own user"
  as_panel "${PHP_BIN}" "${APP_ROOT}/artisan" laravelify:separate-sites --all --no-ansi \
    || say "warning: some websites still run as ${SITE_USER} (see above). They work as before; run this again to retry."
else
  say "New websites get their own user. Existing ones keep ${SITE_USER} until you move them:"
  say "  sudo LARAVELIFY_SITE_USERS=1 bash ${APP_ROOT}/scripts/privileges.sh"
fi

say "Done. Panel: ${PANEL_USER} (${PANEL_SOCKET}, PHP ${PANEL_PHP}). Websites: their own users (lfy-<slug>) or ${SITE_USER}. nginx workers: ${WEB_USER}."
