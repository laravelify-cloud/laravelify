#!/usr/bin/env bash
# Apply rendered Laravelify files onto this Linux host.
# Invoked with sudo by the panel user. Arguments are validated before any path is used.
set -euo pipefail

# PHP-FPM is started with ProtectSystem=full, so /etc is read-only in that
# mount namespace. sudo stays in the same namespace. Ask systemd to run this
# script from the normal system namespace, where /etc is writable.
# `run` does not write under /etc. systemd-run from this process already
# starts the command outside PHP-FPM's mount namespace.
if [[ "${LARAVELIFY_HOST_APPLY_REEXEC:-}" != 1 && "${1:-}" != "run" && "${1:-}" != "run-global" && "${1:-}" != "nginx-log" && "${1:-}" != "nginx-test" && "${1:-}" != "nginx-global-log" && "${1:-}" != "supervisor" && "${1:-}" != "pm2" && "${1:-}" != "ports" ]]; then
  etc_opts="$(findmnt -n -o OPTIONS /etc 2>/dev/null || true)"
  if [[ ",${etc_opts}," == *",ro,"* ]]; then
    exec systemd-run --quiet --pipe --wait --collect \
      --setenv=LARAVELIFY_HOST_APPLY_REEXEC=1 \
      /bin/bash "$0" "$@"
  fi
fi

# PM2 keeps its process list in $PM2_HOME. Under systemd-run there is no HOME, and PM2
# would fall back to /etc/.pm2: a second daemon the panel cannot see and nothing
# restores after a reboot. Every PM2 call uses root's one daemon.
export HOME=/root PM2_HOME=/root/.pm2

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RENDERED="${ROOT}/storage/app/rendered"
ACTION="${1:-}"
TARGET="${2:-}"

die() {
  echo "error: $*" >&2
  exit 1
}

slug_ok() {
  [[ "$1" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]]
}

# mkdir does not chmod directories that already exist.
# `install -d` does, and on a read-only /etc that fails before the site file is written.
ensure_dirs() {
  mkdir -p -- "$@"
}

# Ubuntu's rust `install` unlinks the destination first and can fail with
# "Read-only file system" while the directory is still writable.
write_file() {
  local mode="$1" src="$2" dest="$3"
  cp -f -- "$src" "$dest"
  chmod "$mode" -- "$dest"
}

# Save the PM2 process list and make sure systemd restores it on boot (pm2-root.service).
pm2_persist() {
  pm2 save --force >/dev/null 2>&1 || echo "warning: pm2 save failed" >&2
  if ! systemctl is-enabled --quiet pm2-root 2>/dev/null; then
    pm2 startup systemd -u root --hp /root >/dev/null 2>&1 || echo "warning: could not enable PM2 on boot (pm2-root)" >&2
  fi
}

# Older versions could start apps under /etc/.pm2 (see PM2_HOME above). Stop that daemon
# and start those apps again under root's daemon, so each app runs once and survives reboots.
# A Node site has something to run once a deploy built it. Next.js leaves its server in
# .next/standalone; every other type gets a marker file from the deploy.
node_site_ready() {
  local dir="$1"
  [[ -f "${dir}/.next/standalone/server.js" || -f "${dir}/.laravelify-built" ]]
}

# PM2 files are CommonJS. A .js name breaks in projects with "type": "module" (Nuxt, SvelteKit),
# so they are .cjs; files written by older versions are renamed.
pm2_rename_old_files() {
  local old
  for old in /var/www/*/ecosystem.config.js; do
    [[ -f "${old}" ]] && mv -f -- "${old}" "${old%.js}.cjs"
  done
  return 0
}

pm2_adopt_stray() {
  [[ -d /etc/.pm2 ]] || return 0
  echo "Moving PM2 apps from /etc/.pm2 to /root/.pm2"
  PM2_HOME=/etc/.pm2 pm2 kill >/dev/null 2>&1 || true
  rm -rf /etc/.pm2 || true
  local eco
  for eco in /var/www/*/ecosystem.config.cjs; do
    [[ -f "${eco}" ]] && node_site_ready "$(dirname "${eco}")" || continue
    [[ -f "$(dirname "${eco}")/.laravelify-disabled" ]] && continue
    pm2 startOrReload "${eco}" --update-env >/dev/null 2>&1 || echo "warning: could not start ${eco} in PM2" >&2
  done
}

version_ok() {
  [[ "$1" =~ ^8\.[0-9]+$ ]]
}

# The site's nginx file plus everything it includes: Security tab rules, blocked IPs,
# and the Monitoring tab's JSON request log. Security files go first because the
# site config includes the server-level snippet; they are always written so the
# include never points at a missing file.
install_site_nginx() {
  local slug="$1" src="$2"
  local sec_dir=/etc/nginx/laravelify-security
  ensure_dirs /etc/nginx/sites-available /etc/nginx/sites-enabled "${sec_dir}" /etc/nginx/conf.d

  # Monitoring: shared log format, and the visitor IP (trusts CF-Connecting-IP only
  # from a local cloudflared). nginx (root) writes the logs; the panel (www-data) reads them.
  mkdir -p /var/log/laravelify
  chown root:www-data /var/log/laravelify || true
  chmod 750 /var/log/laravelify
  # Created now so the Monitoring tab sees tracking as on before the first visit.
  touch "/var/log/laravelify/${slug}.metrics.log"
  chmod 644 "/var/log/laravelify/${slug}.metrics.log"
  cat > /etc/nginx/conf.d/laravelify-metrics.conf <<'NGINX'
# Written by Laravelify (host-apply.sh). Request log for the Monitoring tab.
map "$remote_addr|$http_cf_connecting_ip" $laravelify_client_ip {
    default $remote_addr;
    "~^(?:127\.0\.0\.1|::1)\|(?<laravelify_cf_ip>[0-9A-Fa-f:.]+)$" $laravelify_cf_ip;
}
log_format laravelify_metrics escape=json '{"t":"$msec","ip":"$laravelify_client_ip","m":"$request_method","u":"$request_uri","s":"$status","b":"$body_bytes_sent","d":"$request_time","ua":"$http_user_agent","r":"$http_referer","c":"$http_cf_ipcountry"}';
NGINX
  chmod 644 /etc/nginx/conf.d/laravelify-metrics.conf
  if [[ -d /etc/logrotate.d ]]; then
    cat > /etc/logrotate.d/laravelify-metrics <<'ROTATE'
# Written by Laravelify. The panel reads these every minute, so old lines are not kept.
/var/log/laravelify/*.metrics.log {
    daily
    maxsize 50M
    rotate 0
    missingok
    notifempty
    copytruncate
}
ROTATE
  fi

  if [[ -f "${src}/security-server.conf" ]]; then
    write_file 644 "${src}/security-server.conf" "${sec_dir}/${slug}.conf"
  else
    echo "# Laravelify security: nothing enabled" > "${sec_dir}/${slug}.conf"
  fi
  if [[ -s "${src}/security-http.conf" ]]; then
    write_file 644 "${src}/security-http.conf" "/etc/nginx/conf.d/laravelify-security-${slug}.conf"
  else
    rm -f "/etc/nginx/conf.d/laravelify-security-${slug}.conf"
  fi
  if [[ -s "${src}/security.htpasswd" ]]; then
    write_file 640 "${src}/security.htpasswd" "${sec_dir}/${slug}.htpasswd"
    chown root:www-data "${sec_dir}/${slug}.htpasswd" || true
  else
    rm -f "${sec_dir}/${slug}.htpasswd"
  fi
  write_file 644 "${src}/nginx.conf" "/etc/nginx/sites-available/laravelify-${slug}.conf"
  ln -sfn "/etc/nginx/sites-available/laravelify-${slug}.conf" "/etc/nginx/sites-enabled/laravelify-${slug}.conf"
}

case "${ACTION}" in
  site)
    slug_ok "${TARGET}" || die "invalid slug"
    KIND="${3:-}"
    # php = nginx → PHP-FPM, node = a server under PM2, static = nginx serves files.
    case "${KIND}" in
      laravel|php) RUNTIME=php ;;
      nextjs|nuxt|sveltekit|express|nestjs|node) RUNTIME=node ;;
      react|vue|angular|svelte|astro|static) RUNTIME=static ;;
      *) die "invalid site kind" ;;
    esac
    SRC="${RENDERED}/sites/${TARGET}"
    [[ -f "${SRC}/nginx.conf" ]] || die "missing rendered nginx config"
    ensure_dirs /etc/nginx/sites-available /etc/nginx/sites-enabled "/var/www/${TARGET}"
    if [[ "${KIND}" == "laravel" ]]; then
      ensure_dirs \
        "/var/www/${TARGET}/public" \
        "/var/www/${TARGET}/storage/logs" \
        "/var/www/${TARGET}/storage/framework/cache" \
        "/var/www/${TARGET}/storage/framework/sessions" \
        "/var/www/${TARGET}/storage/framework/views" \
        "/var/www/${TARGET}/bootstrap/cache"
      touch "/var/www/${TARGET}/storage/logs/queue.log" \
        "/var/www/${TARGET}/storage/logs/scheduler.log" \
        "/var/www/${TARGET}/storage/logs/reverb.log"
    fi
    chown -R www-data:www-data "/var/www/${TARGET}" || true
    rm -f "/var/www/${TARGET}/.laravelify-disabled"
    install_site_nginx "${TARGET}" "${SRC}"
    if [[ -f "${SRC}/.env" ]]; then
      write_file 640 "${SRC}/.env" "/var/www/${TARGET}/.env"
      chown www-data:www-data "/var/www/${TARGET}/.env" || true
      # A cached config ignores .env entirely, so drop it. The next deploy caches it again.
      rm -f "/var/www/${TARGET}/bootstrap/cache/config.php"
    fi
    if [[ "${KIND}" == "laravel" ]]; then
      ensure_dirs /etc/supervisor/conf.d
      pm2_rename_old_files
      write_file 644 "${SRC}/process.conf" "/etc/supervisor/conf.d/laravelify-${TARGET}.conf"
      supervisorctl reread
      supervisorctl update
      # update only touches programs whose config changed. Restart every program of
      # this site so workers load new code and env, and ones that gave up (FATAL,
      # e.g. started before the first deploy) come back.
      for program in $(supervisorctl status 2>/dev/null | awk '{print $1}' | grep -E "^${TARGET}-" || true); do
        supervisorctl restart "${program}" || true
      done
    elif [[ "${RUNTIME}" == "node" ]]; then
      write_file 644 "${SRC}/process.conf" "/var/www/${TARGET}/ecosystem.config.cjs"
      SITE_DIR="/var/www/${TARGET}"
      if ! node_site_ready "${SITE_DIR}"; then
        # Saved before the first deploy: there is nothing to run yet. Not an error.
        echo "PM2 not started: the site has not been built yet. Deploy it first."
      elif command -v pm2 >/dev/null 2>&1; then
        # Next.js reads the .env next to server.js; keep it in step with the saved one.
        if [[ -f "${SITE_DIR}/.next/standalone/server.js" && -f "${SITE_DIR}/.env" ]]; then
          cp -f -- "${SITE_DIR}/.env" "${SITE_DIR}/.next/standalone/.env"
          chown www-data:www-data "${SITE_DIR}/.next/standalone/.env" || true
        fi
        pm2_adopt_stray
        pm2 startOrReload "${SITE_DIR}/ecosystem.config.cjs" --update-env
        pm2_persist
      fi
    fi
    nginx -t
    systemctl reload nginx
    ;;
  site-nginx)
    # Security rules, blocked IPs, and the request log, without touching workers.
    slug_ok "${TARGET}" || die "invalid slug"
    SRC="${RENDERED}/sites/${TARGET}"
    [[ -f "${SRC}/nginx.conf" ]] || die "missing rendered nginx config"
    install_site_nginx "${TARGET}" "${SRC}"
    nginx -t
    systemctl reload nginx
    ;;
  cloudflared)
    slug_ok "${TARGET}" || die "invalid slug"
    SRC="${RENDERED}/tunnels/${TARGET}"
    [[ -f "${SRC}/config.yml" && -f "${SRC}/tunnel.service" ]] || die "missing rendered tunnel files"
    ensure_dirs /etc/cloudflared
    UNIT="laravelify-tunnel-${TARGET}.service"
    CHANGED=0
    cmp -s "${SRC}/config.yml" "/etc/cloudflared/laravelify-${TARGET}.yml" || CHANGED=1
    cmp -s "${SRC}/tunnel.service" "/etc/systemd/system/${UNIT}" || CHANGED=1
    write_file 644 "${SRC}/config.yml" "/etc/cloudflared/laravelify-${TARGET}.yml"
    write_file 600 "${SRC}/tunnel.service" "/etc/systemd/system/${UNIT}"
    systemctl daemon-reload
    if ! systemctl is-active --quiet "${UNIT}"; then
      systemctl enable --now "${UNIT}"
    elif [[ "${CHANGED}" == 1 ]]; then
      systemctl enable "${UNIT}" >/dev/null 2>&1 || true
      # The panel is often served through this tunnel. Restart it a few seconds later, so the
      # request that changed it (a page or an MCP call) gets its answer first.
      if command -v systemd-run >/dev/null 2>&1; then
        systemd-run --quiet --on-active=3 --unit="laravelify-tunnel-restart-${TARGET}-$(date +%s)" \
          /bin/systemctl restart "${UNIT}" \
          || setsid bash -c "sleep 3; systemctl restart '${UNIT}'" >/dev/null 2>&1 < /dev/null &
      else
        setsid bash -c "sleep 3; systemctl restart '${UNIT}'" >/dev/null 2>&1 < /dev/null &
      fi
      echo "Tunnel ${TARGET} restarts in 3 seconds with the new routes."
    else
      echo "Tunnel ${TARGET} routes are unchanged."
    fi
    ;;
  remove-tunnel)
    slug_ok "${TARGET}" || die "invalid slug"
    systemctl disable --now "laravelify-tunnel-${TARGET}.service" || true
    rm -f "/etc/systemd/system/laravelify-tunnel-${TARGET}.service" "/etc/cloudflared/laravelify-${TARGET}.yml"
    systemctl daemon-reload || true
    ;;
  mysql)
    [[ -f "${RENDERED}/services/mysql.cnf" ]] || die "missing mysql config"
    ensure_dirs /etc/mysql/mysql.conf.d
    write_file 644 "${RENDERED}/services/mysql.cnf" /etc/mysql/mysql.conf.d/laravelify.cnf
    systemctl restart mysql
    ;;
  redis)
    [[ -f "${RENDERED}/services/redis.conf" ]] || die "missing redis config"
    ensure_dirs /etc/redis
    write_file 644 "${RENDERED}/services/redis.conf" /etc/redis/laravelify.conf
    if [[ -f /etc/redis/redis.conf ]] && ! grep -q '/etc/redis/laravelify.conf' /etc/redis/redis.conf; then
      printf '\ninclude /etc/redis/laravelify.conf\n' >> /etc/redis/redis.conf
    fi
    systemctl restart redis-server
    ;;
  phpmyadmin)
    [[ -f "${RENDERED}/services/phpmyadmin.conf" ]] || die "missing phpmyadmin nginx config"
    ensure_dirs /etc/nginx/sites-available /etc/nginx/sites-enabled
    write_file 644 "${RENDERED}/services/phpmyadmin.conf" /etc/nginx/sites-available/laravelify-phpmyadmin.conf
    ln -sfn /etc/nginx/sites-available/laravelify-phpmyadmin.conf /etc/nginx/sites-enabled/laravelify-phpmyadmin.conf
    nginx -t
    systemctl reload nginx
    ;;
  server-patches)
    # Daily patch check for Telegram alerts: refresh the package lists, then list
    # upgradable packages. Nothing is installed.
    apt-get update -qq >/dev/null 2>&1 || true
    apt list --upgradable 2>/dev/null || true
    ;;
  phpmyadmin-sso)
    # One-click sign-in from the panel: an extra phpMyAdmin server with
    # auth_type "signon", made the default. Server 1 keeps the password login.
    ensure_dirs /etc/phpmyadmin/conf.d
    cat > /etc/phpmyadmin/conf.d/laravelify-signon.php <<'PHP'
<?php
// Written by Laravelify (host-apply.sh phpmyadmin-sso). Re-run from the panel to restore.
// Sign-in comes from the panel through /laravelify-signon.php. Server 1 keeps the password form.
if (isset($cfg['Servers'][1])) {
    $i++;
    $cfg['Servers'][$i] = $cfg['Servers'][1];
    $cfg['Servers'][$i]['verbose'] = 'Laravelify sign-in';
    $cfg['Servers'][$i]['auth_type'] = 'signon';
    $cfg['Servers'][$i]['host'] = '127.0.0.1';
    $cfg['Servers'][$i]['SignonSession'] = 'LaravelifySignon';
    $cfg['Servers'][$i]['SignonURL'] = '/laravelify-signon.php';
    $cfg['Servers'][$i]['LogoutURL'] = '/laravelify-signon.php?logout=1';
    $cfg['ServerDefault'] = $i;
}
PHP
    chmod 644 /etc/phpmyadmin/conf.d/laravelify-signon.php
    echo "phpMyAdmin one-click sign-in is installed."
    ;;
  php-fpm)
    version_ok "${TARGET}" || die "invalid php version"
    [[ -f "${RENDERED}/services/php-fpm-${TARGET}.conf" ]] || die "missing php-fpm pool"
    ensure_dirs "/etc/php/${TARGET}/fpm/pool.d"
    write_file 644 "${RENDERED}/services/php-fpm-${TARGET}.conf" "/etc/php/${TARGET}/fpm/pool.d/www.conf"
    systemctl restart "php${TARGET}-fpm"
    ;;
  mkdir-site)
    slug_ok "${TARGET}" || die "invalid slug"
    mkdir -p "/var/www/${TARGET}"
    if [[ "$(id -u)" -eq 0 ]]; then
      chown -R www-data:www-data "/var/www/${TARGET}"
    fi
    ;;
  run)
    [[ -n "${3:-}" ]] || die "missing command"
    root="$(realpath -e -- "${TARGET}")" || die "missing site folder"
    case "${root}" in
      /var/www/*) ;;
      *) die "path is outside a site folder" ;;
    esac
    [[ "${root}" != "/var/www" && -d "${root}" ]] || die "path is outside a site folder"
    exec systemd-run --quiet --pipe --wait --collect \
      --uid=www-data --gid=www-data \
      --working-directory="${root}" \
      --property=UnsetEnvironment=yes \
      --setenv=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
      --setenv=HOME="${root}" \
      --setenv=LANG=C.UTF-8 \
      --property=ProtectSystem=strict \
      --property=ProtectHome=yes \
      --property=PrivateTmp=yes \
      --property=ReadWritePaths="${root}" \
      --property=NoNewPrivileges=yes \
      --property=RuntimeMaxSec=60 \
      /bin/bash -lc "$3"
    ;;
  run-global)
    # The panel's global terminal: same sandbox as "run", but any folder under
    # /var/www (including /var/www itself) and write access to all sites.
    [[ -n "${3:-}" ]] || die "missing command"
    root="$(realpath -e -- "${TARGET}")" || die "missing folder"
    case "${root}" in
      /var/www|/var/www/*) ;;
      *) die "path is outside /var/www" ;;
    esac
    [[ -d "${root}" ]] || die "path is not a folder"
    exec systemd-run --quiet --pipe --wait --collect \
      --uid=www-data --gid=www-data \
      --working-directory="${root}" \
      --property=UnsetEnvironment=yes \
      --setenv=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
      --setenv=HOME="${root}" \
      --setenv=LANG=C.UTF-8 \
      --property=ProtectSystem=strict \
      --property=ProtectHome=yes \
      --property=PrivateTmp=yes \
      --property=ReadWritePaths=/var/www \
      --property=NoNewPrivileges=yes \
      --property=RuntimeMaxSec=60 \
      /bin/bash -lc "$3"
    ;;
  nginx-log)
    # Read-only: the last lines of one site's nginx access or error log.
    slug_ok "${TARGET}" || die "invalid slug"
    case "${3:-}" in
      access|error) ;;
      *) die "unknown log" ;;
    esac
    [[ "${4:-}" =~ ^[0-9]{1,4}$ ]] || die "invalid line count"
    file="/var/log/nginx/laravelify-${TARGET}.${3}.log"
    if [[ ! -f "${file}" ]]; then
      echo "No ${3} log yet at ${file}."
      exit 0
    fi
    exec tail -n "$4" -- "${file}"
    ;;
  supervisor)
    # supervisorctl needs root to reach the Supervisor socket.
    # Usage: supervisor status all | supervisor restart|start|stop|tail <program>
    case "${TARGET}" in
      status)
        supervisorctl status || true
        exit 0
        ;;
      restart|start|stop|tail) ;;
      *) die "unknown supervisor command" ;;
    esac
    [[ "${3:-}" =~ ^[A-Za-z0-9_.:-]+$ ]] || die "invalid program name"
    if [[ "${TARGET}" == "tail" ]]; then
      exec supervisorctl tail -8000 "$3"
    fi
    exec supervisorctl "${TARGET}" "$3"
    ;;
  pm2)
    # Site PM2 apps run under root's PM2 daemon (started by the site action).
    # Usage: pm2 jlist all | pm2 restart|delete|logs <name>
    command -v pm2 >/dev/null 2>&1 || { echo "[]"; exit 0; }
    case "${TARGET}" in
      jlist)
        exec pm2 jlist
        ;;
      restart|delete|logs) ;;
      *) die "unknown pm2 command" ;;
    esac
    [[ "${3:-}" =~ ^[A-Za-z0-9_.:-]+$ ]] || die "invalid process name"
    case "${TARGET}" in
      restart) pm2 restart "$3" --update-env && pm2_persist ;;
      delete) pm2 delete "$3" && pm2_persist ;;
      logs) exec pm2 logs "$3" --nostream --lines 400 --raw ;;
    esac
    ;;
  pm2-boot)
    # Run by update.sh: one PM2 daemon under /root, every Node site that has a build
    # started in it, the list saved, and pm2-root enabled so it all comes back after a reboot.
    command -v pm2 >/dev/null 2>&1 || { echo "PM2 is not installed."; exit 0; }
    pm2_rename_old_files
    pm2_adopt_stray
    for eco in /var/www/*/ecosystem.config.cjs; do
      [[ -f "${eco}" ]] && node_site_ready "$(dirname "${eco}")" || continue
      [[ -f "$(dirname "${eco}")/.laravelify-disabled" ]] && continue
      name="$(basename "$(dirname "${eco}")")"
      if ! pm2 describe "${name}" >/dev/null 2>&1; then
        echo "Starting ${name} in PM2"
        pm2 startOrReload "${eco}" --update-env >/dev/null 2>&1 || echo "warning: could not start ${name}" >&2
      fi
    done
    pm2_persist
    systemctl is-enabled --quiet pm2-root 2>/dev/null && echo "PM2 apps are saved and start on boot (pm2-root)."
    ;;
  ports)
    # Read-only: listening TCP ports with the processes behind them (names need root).
    exec ss -H -lntp
    ;;
  nginx-global-log)
    # Read-only: the last lines of nginx's own error log (root-only), for the Nginx page.
    [[ "${2:-}" == "error" ]] || die "unknown log"
    [[ "${3:-}" =~ ^[0-9]{1,4}$ ]] || die "invalid line count"
    if [[ ! -f /var/log/nginx/error.log ]]; then
      echo "No error log yet at /var/log/nginx/error.log."
      exit 0
    fi
    exec tail -n "$3" -- /var/log/nginx/error.log
    ;;
  nginx-test)
    # Read-only: validate every nginx config.
    exec nginx -t
    ;;
  site-disable)
    # Turned off: nginx answers with a "turned off" page and the site's processes stop.
    # Files, database, and settings stay; "site" turns everything on again.
    slug_ok "${TARGET}" || die "invalid slug"
    SRC="${RENDERED}/sites/${TARGET}"
    [[ -f "${SRC}/nginx.conf" ]] || die "missing rendered nginx config"
    install_site_nginx "${TARGET}" "${SRC}"
    # pm2-boot and later updates leave a turned-off site alone.
    [[ -d "/var/www/${TARGET}" ]] && touch "/var/www/${TARGET}/.laravelify-disabled"
    if [[ -f "/etc/supervisor/conf.d/laravelify-${TARGET}.conf" ]]; then
      rm -f "/etc/supervisor/conf.d/laravelify-${TARGET}.conf"
      supervisorctl reread || true
      supervisorctl update || true
    fi
    if command -v pm2 >/dev/null 2>&1 && pm2 describe "${TARGET}" >/dev/null 2>&1; then
      pm2 delete "${TARGET}" || true
      pm2_persist
    fi
    nginx -t
    systemctl reload nginx
    ;;
  remove-site)
    slug_ok "${TARGET}" || die "invalid slug"
    rm -f "/etc/nginx/sites-enabled/laravelify-${TARGET}.conf" \
      "/etc/nginx/sites-available/laravelify-${TARGET}.conf" \
      "/etc/nginx/laravelify-security/${TARGET}.conf" \
      "/etc/nginx/laravelify-security/${TARGET}.htpasswd" \
      "/etc/nginx/conf.d/laravelify-security-${TARGET}.conf" \
      "/etc/supervisor/conf.d/laravelify-${TARGET}.conf" \
      "/var/log/laravelify/${TARGET}.metrics.log"
    nginx -t && systemctl reload nginx || true
    supervisorctl reread || true
    supervisorctl update || true
    if command -v pm2 >/dev/null 2>&1; then
      pm2 delete "${TARGET}" || true
      pm2_persist
    fi
    ;;
  *)
    die "unknown action: ${ACTION}"
    ;;
esac
