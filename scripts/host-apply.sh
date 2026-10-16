#!/usr/bin/env bash
# Apply rendered Laravelify files onto this Linux host.
# Invoked with sudo by the panel user. Arguments are validated before any path is used.
#
# Websites run as www-data and cannot read the panel, so nothing they can write is ever trusted
# here: rendered files come only from the panel's own storage, every nginx, Supervisor, and
# PHP-FPM file is checked before it is installed, and root never writes through a path inside a
# site folder (those writes run as www-data, which can only touch what it already owns).
set -euo pipefail

# PHP-FPM is started with ProtectSystem=full, so /etc is read-only in that
# mount namespace. sudo stays in the same namespace. Ask systemd to run this
# script from the normal system namespace, where /etc is writable.
# `run` does not write under /etc. systemd-run from this process already
# starts the command outside PHP-FPM's mount namespace.
case "${1:-}" in
  run|run-global|nginx-log|nginx-test|nginx-global-log|supervisor|pm2|ports|program-log-clear) ;;
  *)
    if [[ "${LARAVELIFY_HOST_APPLY_REEXEC:-}" != 1 ]]; then
      etc_opts="$(findmnt -n -o OPTIONS /etc 2>/dev/null || true)"
      if [[ ",${etc_opts}," == *",ro,"* ]]; then
        exec systemd-run --quiet --pipe --wait --collect \
          --setenv=LARAVELIFY_HOST_APPLY_REEXEC=1 \
          /bin/bash "$0" "$@"
      fi
    fi
    ;;
esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RENDERED="${ROOT}/storage/app/rendered"
ACTION="${1:-}"
TARGET="${2:-}"

SITE_USER=www-data
# The panel's own user. Servers not migrated yet still run the panel as www-data.
if id -u laravelify >/dev/null 2>&1; then
  PANEL_USER=laravelify
else
  PANEL_USER=www-data
fi
STATE_DIR=/var/lib/laravelify
SITE_HOME="${STATE_DIR}/home"
PM2_APPS="${STATE_DIR}/pm2"
SITE_LOGS=/var/log/laravelify/sites
PATH_SAFE=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

die() {
  echo "error: $*" >&2
  exit 1
}

slug_ok() {
  [[ "$1" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]]
}

version_ok() {
  [[ "$1" =~ ^8\.[0-9]+$ ]]
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

# A command as the site user. Used for every write inside /var/www: a site can turn any of its
# paths into a link, and root would follow it; www-data can only reach what it already owns.
as_site() {
  runuser -u "${SITE_USER}" -- env -i PATH="${PATH_SAFE}" HOME="${SITE_HOME}" LANG=C.UTF-8 "$@"
}

# Copy a panel file into a site folder as the site user (root reads the source, www-data writes).
write_site_file() {
  local mode="$1" src="$2" dest="$3"
  as_site /bin/sh -c 'set -e; t="$1.laravelify-$$"; cat > "$t"; chmod "$2" -- "$t"; mv -f -T -- "$t" "$1"' sh "${dest}" "${mode}" < "${src}"
}

# Rendered files must come from the panel: owned by root or the panel user, and writable by
# nobody else. Website code (www-data) cannot write the panel's storage once it has its own user.
trusted_source() {
  local path="$1" owner mode
  [[ -e "${path}" && ! -L "${path}" ]] || die "missing ${path#"${ROOT}"/}"
  while [[ "${path}" != "${ROOT}" && "${path}" != / ]]; do
    [[ -L "${path}" ]] && die "${path#"${ROOT}"/} is a link"
    owner="$(stat -c '%U' -- "${path}")"
    mode="$(stat -c '%a' -- "${path}")"
    [[ "${owner}" == root || "${owner}" == "${PANEL_USER}" ]] || die "${path#"${ROOT}"/} is not owned by the panel (${owner})"
    # Group or other write (the middle and last octal digit carry 2).
    if (( (8#${mode} & 8#022) != 0 )) && [[ "${PANEL_USER}" != "${SITE_USER}" ]]; then
      die "${path#"${ROOT}"/} is writable by other users (mode ${mode})"
    fi
    path="$(dirname -- "${path}")"
  done
}

# nginx's master process runs as root: it opens log files, reads includes, and creates (and
# chowns) temp and cache folders as root. So a site's nginx files may only log to the log
# folders, include files under /etc/nginx, and keep temp and cache folders under nginx's own.
# Statements are split the way nginx reads them (quotes, escapes, "#" comments at a word start,
# several statements on a line), so nothing hides inside a quoted string or behind a comment.
validate_nginx() {
  local file="$1"
  awk '
    function fail(message) { printf "error: nginx config: %s\n", message > "/dev/stderr"; bad = 1 }
    function flush() {
      if (inword) { words[++count] = word }
      word = ""; inword = 0
    }
    function check(   directive, value) {
      flush()
      if (count == 0) { return }
      directive = words[1]; value = words[2]
      if (directive ~ /^(load_module|user|pid|lua_|perl|js_|env$|working_directory|worker_|ssl_engine)/ || directive ~ /_by_lua/) {
        fail("\"" directive "\" is not allowed in a site config")
      } else if (directive == "access_log" || directive == "error_log") {
        if (value != "off" && value != "stderr" && value !~ /^syslog:/ && (value !~ /^\/var\/log\/(nginx|laravelify)\/[A-Za-z0-9._\/-]+$/ || value ~ /\.\./)) {
          fail(directive " " value ": logs go to /var/log/nginx or /var/log/laravelify")
        }
      } else if (directive == "include") {
        if (value ~ /\.\./ || (value ~ /^\// && value !~ /^\/etc\/nginx\//)) {
          fail("include " value ": includes stay under /etc/nginx")
        }
      } else if (directive ~ /_(temp|cache)_path$/) {
        if (value ~ /\.\./ || value !~ /^\/var\/(cache|lib)\/nginx\//) {
          fail(directive " " value ": use a folder under /var/cache/nginx or /var/lib/nginx")
        }
      }
      count = 0
      split("", words)
    }
    {
      line = $0 "\n"
      length_ = length(line)
      for (i = 1; i <= length_; i++) {
        c = substr(line, i, 1)
        if (quote != "") {
          if (c == "\\") { word = word substr(line, i + 1, 1); i++; continue }
          if (c == quote) { quote = ""; continue }
          word = word c
          continue
        }
        if (c == "\\") { word = word substr(line, i + 1, 1); i++; inword = 1; continue }
        if (!inword && (c == "\"" || c == "\047")) { quote = c; inword = 1; continue }
        if (!inword && c == "#") { break }
        if (c == " " || c == "\t" || c == "\r" || c == "\n") { flush(); continue }
        if (c == ";" || c == "{" || c == "}") { check(); continue }
        word = word c
        inword = 1
      }
    }
    END { check(); exit bad }
  ' "${file}" || die "nginx config refused (${file##*/})"
}

# Supervisor runs as root: every program of a site must run as the site user and log into the
# site's root-owned log folder, and nothing else (includes, event listeners) is accepted.
# Read the way Supervisor reads it: "=" or ":" separates keys, keys ignore case, an indented
# line continues the value above, and " ;" starts an inline comment.
validate_supervisor() {
  local file="$1" slug="$2"
  awk -v slug="${slug}" -v user="${SITE_USER}" -v logs="${SITE_LOGS}/${slug}/" '
    function fail(message) { printf "error: Supervisor config line %d: %s\n", NR, message > "/dev/stderr"; bad = 1 }
    function close_program() {
      if (program != "" && has_user == 0) {
        printf "error: Supervisor program %s has no user=%s\n", program, user > "/dev/stderr"
        bad = 1
      }
      program = ""; has_user = 0
    }
    {
      raw = $0
      sub(/\r$/, "", raw)
      if (raw ~ /^[ \t]/ || raw ~ /^[;#]/ || raw ~ /^[ \t]*$/) { next }
      if (substr(raw, 1, 1) == "[") {
        close_program()
        section = raw
        sub(/^\[/, "", section)
        sub(/\][^\]]*$/, "", section)
        if (section ~ ("^program:" slug "(-[A-Za-z0-9_.-]+)?$")) {
          program = substr(section, 9)
        } else if (section !~ ("^group:" slug "(-[A-Za-z0-9_.-]+)?$")) {
          fail("section [" section "] is not allowed; use [program:" slug "-name]")
          program = ""
        }
        next
      }
      split_at = match(raw, /[=:]/)
      if (split_at == 0) { next }
      key = tolower(substr(raw, 1, split_at - 1))
      gsub(/^[ \t]+|[ \t]+$/, "", key)
      value = substr(raw, split_at + 1)
      sub(/[ \t]+[;#].*$/, "", value)
      gsub(/^[ \t]+|[ \t]+$/, "", value)
      if (program == "") { next }
      if (key == "user") {
        if (value != user) { fail("program " program " must run as " user) }
        has_user = 1
      } else if (key == "stdout_logfile" || key == "stderr_logfile") {
        if (value != "AUTO" && value != "NONE" && (index(value, logs) != 1 || substr(value, length(logs) + 1) !~ /^[A-Za-z0-9_.-]+$/)) {
          fail(key " must be a file in " logs)
        }
      }
    }
    END { close_program(); exit bad }
  ' "${file}" || die "Supervisor config refused"
}

# The sites' PHP-FPM pool must run as the site user (never as the panel user or root).
validate_fpm_pool() {
  local file="$1"
  awk -v user="${SITE_USER}" '
    function fail(message) { print "error: PHP-FPM pool line " NR ": " message > "/dev/stderr"; bad = 1 }
    {
      line = $0
      sub(/^[ \t]*;.*/, "", line)
      gsub(/^[ \t]+|[ \t]+$/, "", line)
      if (line == "") next
      key = line; sub(/[ \t]*=.*/, "", key)
      value = line; sub(/^[^=]*=[ \t]*/, "", value)
      gsub(/^["\047]|["\047]$/, "", value)
      if ((key == "user" || key == "group" || key == "listen.owner") && value != user) fail(key " must be " user)
      if (key == "include" || key == "chroot" || key == "prefix") fail(key " is not allowed")
      if (key == "listen" && value ~ /laravelify/) fail("that socket belongs to the panel")
    }
    END { exit bad }
  ' "${file}" || die "PHP-FPM pool refused"
}

# --- PM2 ---------------------------------------------------------------------------------
# Node sites run in the site user's PM2 daemon (PM2_HOME under /var/lib/laravelify/home),
# restored on boot by pm2-www-data. PM2 files are JavaScript that PM2 runs, so they live in a
# root-owned folder, never in the site folder. Older versions ran apps in root's daemon.

site_pm2() {
  ensure_pm2_home
  as_site env PM2_HOME="${SITE_HOME}/.pm2" pm2 "$@"
}

root_pm2() {
  HOME=/root PM2_HOME=/root/.pm2 pm2 "$@"
}

ensure_pm2_home() {
  if [[ ! -d "${SITE_HOME}/.pm2" ]]; then
    install -d -o root -g root -m 0755 "${STATE_DIR}"
    install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0750 "${SITE_HOME}" "${SITE_HOME}/.pm2"
  fi
}

# Save the site daemon's list and make sure systemd restores it on boot.
pm2_persist() {
  site_pm2 save --force >/dev/null 2>&1 || echo "warning: pm2 save failed" >&2
  if ! systemctl is-enabled --quiet "pm2-${SITE_USER}" 2>/dev/null; then
    env PATH="${PATH_SAFE}" pm2 startup systemd -u "${SITE_USER}" --hp "${SITE_HOME}" >/dev/null 2>&1 \
      || echo "warning: could not enable PM2 on boot (pm2-${SITE_USER})" >&2
  fi
}

# Stop an app of older versions in root's daemon (and the stray /etc/.pm2 one), so the site
# daemon can take its port.
pm2_leave_root() {
  local name="$1" changed=0
  if [[ -d /etc/.pm2 ]]; then
    PM2_HOME=/etc/.pm2 pm2 kill >/dev/null 2>&1 || true
    rm -rf /etc/.pm2 || true
  fi
  if [[ -d /root/.pm2 ]] && root_pm2 describe "${name}" >/dev/null 2>&1; then
    echo "Moving ${name} from root's PM2 to ${SITE_USER}'s PM2"
    root_pm2 delete "${name}" >/dev/null 2>&1 || true
    changed=1
  fi
  if [[ "${changed}" == 1 ]]; then
    root_pm2 save --force >/dev/null 2>&1 || true
  fi
  return 0
}

# A Node site has something to run once a deploy built it. Next.js leaves its server in
# .next/standalone; every other type gets a marker file from the deploy.
node_site_ready() {
  local dir="$1"
  [[ -f "${dir}/.next/standalone/server.js" || -f "${dir}/.laravelify-built" ]]
}

pm2_start_site() {
  local slug="$1"
  pm2_leave_root "${slug}"
  site_pm2 startOrReload "${PM2_APPS}/${slug}.config.cjs" --update-env
  pm2_persist
}

pm2_install_config() {
  local slug="$1" src="$2"
  install -d -o root -g root -m 0755 "${STATE_DIR}" "${PM2_APPS}"
  write_file 644 "${src}" "${PM2_APPS}/${slug}.config.cjs"
  chown root:root "${PM2_APPS}/${slug}.config.cjs"
}

# --- nginx -------------------------------------------------------------------------------

# Ports a site's nginx file listens on for everyone: not 80/443 (always open), not 127.0.0.1.
site_public_ports() {
  [[ -f "$1" ]] || return 0
  grep -oE '^[[:space:]]*listen[[:space:]]+[^;]+' "$1" | awk '{print $2}' | while read -r address; do
    case "${address}" in
      127.0.0.1:*|localhost:*|unix:*|"[::1]":*) continue ;;
    esac
    port="${address##*:}"
    [[ "${port}" =~ ^[0-9]+$ ]] || continue
    [[ "${port}" == 80 || "${port}" == 443 ]] && continue
    echo "${port}"
  done | sort -u
}

# A site without a domain answers on its own port (http://SERVER_IP:8090). When ufw is on, open
# the ports the new file listens on and close the ones only the old file used.
sync_site_firewall() {
  local old="$1" new="$2" port
  command -v ufw >/dev/null 2>&1 || return 0
  ufw status 2>/dev/null | grep -q '^Status: active' || return 0
  for port in $(site_public_ports "${old}"); do
    if ! site_public_ports "${new}" | grep -qx "${port}"; then
      ufw delete allow "${port}/tcp" >/dev/null 2>&1 || true
    fi
  done
  for port in $(site_public_ports "${new}"); do
    ufw allow "${port}/tcp" comment "Laravelify site" >/dev/null 2>&1 || echo "warning: could not open port ${port} in ufw" >&2
  done
}

# The site's nginx file plus everything it includes: Security tab rules, blocked IPs,
# and the Monitoring tab's JSON request log. Security files go first because the
# site config includes the server-level snippet; they are always written so the
# include never points at a missing file.
check_site_nginx() {
  local src="$1" file
  for file in nginx.conf security-server.conf security-http.conf; do
    if [[ -f "${src}/${file}" ]]; then
      trusted_source "${src}/${file}"
      validate_nginx "${src}/${file}"
    fi
  done
  if [[ -f "${src}/security.htpasswd" ]]; then
    trusted_source "${src}/security.htpasswd"
  fi
}

install_site_nginx() {
  local slug="$1" src="$2"
  local sec_dir=/etc/nginx/laravelify-security
  ensure_dirs /etc/nginx/sites-available /etc/nginx/sites-enabled "${sec_dir}" /etc/nginx/conf.d

  # Every site's security rules define a few nginx variables; with many sites nginx's default table
  # (64) overflows ("could not build variables_hash"). Raised once, unless the admin already set it.
  if ! grep -rqsE '^[[:space:]]*variables_hash_bucket_size' /etc/nginx --exclude=laravelify-hash.conf; then
    printf '# Written by Laravelify (host-apply.sh): room for the variables of many sites.
variables_hash_bucket_size 128;
' > /etc/nginx/conf.d/laravelify-hash.conf
    chmod 644 /etc/nginx/conf.d/laravelify-hash.conf
  fi

  # Monitoring: shared log format, and the visitor IP (trusts CF-Connecting-IP only
  # from a local cloudflared). nginx (root) writes the logs; the panel reads them.
  mkdir -p /var/log/laravelify
  chown root:"${PANEL_USER}" /var/log/laravelify || true
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
  local previous
  previous="$(mktemp)"
  cp -f -- "/etc/nginx/sites-available/laravelify-${slug}.conf" "${previous}" 2>/dev/null || : > "${previous}"
  write_file 644 "${src}/nginx.conf" "/etc/nginx/sites-available/laravelify-${slug}.conf"
  sync_site_firewall "${previous}" "/etc/nginx/sites-available/laravelify-${slug}.conf"
  rm -f -- "${previous}"
  ln -sfn "/etc/nginx/sites-available/laravelify-${slug}.conf" "/etc/nginx/sites-enabled/laravelify-${slug}.conf"
}

# --- Site workers ------------------------------------------------------------------------

site_runtime() {
  case "$1" in
    laravel|php) echo php ;;
    nextjs|nuxt|sveltekit|express|nestjs|node) echo node ;;
    react|vue|angular|svelte|astro|static) echo static ;;
    *) die "invalid site kind" ;;
  esac
}

# The Supervisor programs of a Laravel site, or the PM2 app of a Node site.
apply_site_processes() {
  local slug="$1" kind="$2" src="$3" runtime
  runtime="$(site_runtime "${kind}")"
  local site_dir="/var/www/${slug}"

  if [[ "${kind}" == "laravel" ]]; then
    trusted_source "${src}/process.conf"
    validate_supervisor "${src}/process.conf" "${slug}"
    install -d -o root -g "${PANEL_USER}" -m 0750 /var/log/laravelify "${SITE_LOGS}" "${SITE_LOGS}/${slug}"
    ensure_dirs /etc/supervisor/conf.d
    write_file 644 "${src}/process.conf" "/etc/supervisor/conf.d/laravelify-${slug}.conf"
    supervisorctl reread
    supervisorctl update
    # update only touches programs whose config changed. Restart every program of
    # this site so workers load new code and env, and ones that gave up (FATAL,
    # e.g. started before the first deploy) come back.
    for program in $(supervisorctl status 2>/dev/null | awk '{print $1}' | grep -E "^${slug}-" || true); do
      supervisorctl restart "${program}" || true
    done
  elif [[ "${runtime}" == "node" ]]; then
    trusted_source "${src}/process.conf"
    pm2_install_config "${slug}" "${src}/process.conf"
    if ! node_site_ready "${site_dir}"; then
      # Saved before the first deploy: there is nothing to run yet. Not an error.
      echo "PM2 not started: the site has not been built yet. Deploy it first."
    elif command -v pm2 >/dev/null 2>&1; then
      # Next.js reads the .env next to server.js; keep it in step with the saved one.
      if [[ -f "${site_dir}/.next/standalone/server.js" && -f "${site_dir}/.env" ]]; then
        as_site cp -f -- "${site_dir}/.env" "${site_dir}/.next/standalone/.env"
      fi
      pm2_start_site "${slug}"
    fi
  fi
}

# Files root left in a site folder (older versions ran PM2 apps as root) go back to the site
# user. chown -R -P never follows links.
own_site_folder() {
  local site_dir="$1"
  [[ -d "${site_dir}" && ! -L "${site_dir}" ]] || return 0
  chown -R -P -h "${SITE_USER}:${SITE_USER}" -- "${site_dir}" || true
}

case "${ACTION}" in
  site)
    slug_ok "${TARGET}" || die "invalid slug"
    KIND="${3:-}"
    RUNTIME="$(site_runtime "${KIND}")"
    SRC="${RENDERED}/sites/${TARGET}"
    SITE_DIR="/var/www/${TARGET}"
    [[ -f "${SRC}/nginx.conf" ]] || die "missing rendered nginx config"
    # Check everything before anything is written: a refused file leaves the live site as it is.
    check_site_nginx "${SRC}"
    if [[ "${KIND}" == "laravel" ]]; then
      trusted_source "${SRC}/process.conf"
      validate_supervisor "${SRC}/process.conf" "${TARGET}"
    fi
    [[ -f "${SRC}/.env" ]] && trusted_source "${SRC}/.env"

    ensure_dirs /etc/nginx/sites-available /etc/nginx/sites-enabled
    if [[ ! -e "${SITE_DIR}" ]]; then
      mkdir -p -- "${SITE_DIR}"
    fi
    [[ -d "${SITE_DIR}" && ! -L "${SITE_DIR}" ]] || die "${SITE_DIR} is not a folder"
    own_site_folder "${SITE_DIR}"
    if [[ "${KIND}" == "laravel" ]]; then
      as_site mkdir -p -- \
        "${SITE_DIR}/public" \
        "${SITE_DIR}/storage/logs" \
        "${SITE_DIR}/storage/framework/cache" \
        "${SITE_DIR}/storage/framework/sessions" \
        "${SITE_DIR}/storage/framework/views" \
        "${SITE_DIR}/bootstrap/cache"
    fi
    as_site rm -f -- "${SITE_DIR}/.laravelify-disabled"
    install_site_nginx "${TARGET}" "${SRC}"
    if [[ -f "${SRC}/.env" ]]; then
      write_site_file 640 "${SRC}/.env" "${SITE_DIR}/.env"
      # A cached config ignores .env entirely, so drop it. The next deploy caches it again.
      as_site rm -f -- "${SITE_DIR}/bootstrap/cache/config.php"
    fi
    apply_site_processes "${TARGET}" "${KIND}" "${SRC}"
    nginx -t
    systemctl reload nginx
    ;;
  site-processes)
    # Only the workers (Supervisor programs or the PM2 app); .env and nginx stay as they are.
    slug_ok "${TARGET}" || die "invalid slug"
    KIND="${3:-}"
    site_runtime "${KIND}" >/dev/null
    SRC="${RENDERED}/sites/${TARGET}"
    [[ -f "${SRC}/process.conf" ]] || die "missing rendered process config"
    own_site_folder "/var/www/${TARGET}"
    apply_site_processes "${TARGET}" "${KIND}" "${SRC}"
    ;;
  site-nginx)
    # Security rules, blocked IPs, and the request log, without touching workers.
    slug_ok "${TARGET}" || die "invalid slug"
    SRC="${RENDERED}/sites/${TARGET}"
    [[ -f "${SRC}/nginx.conf" ]] || die "missing rendered nginx config"
    check_site_nginx "${SRC}"
    install_site_nginx "${TARGET}" "${SRC}"
    nginx -t
    systemctl reload nginx
    ;;
  cloudflared)
    slug_ok "${TARGET}" || die "invalid slug"
    SRC="${RENDERED}/tunnels/${TARGET}"
    [[ -f "${SRC}/config.yml" && -f "${SRC}/tunnel.service" ]] || die "missing rendered tunnel files"
    trusted_source "${SRC}/config.yml"
    trusted_source "${SRC}/tunnel.service"
    # cloudflared runs as root. Only the token is taken from the rendered unit (the unit itself
    # is written here), and the config may not name files for cloudflared to write.
    TOKEN="$(sed -n 's|^ExecStart=.* run --token "\([A-Za-z0-9=_.+/-]*\)"$|\1|p' "${SRC}/tunnel.service" | head -n 1)"
    [[ -n "${TOKEN}" ]] || die "no tunnel token in the rendered unit"
    if grep -qiE '^[[:space:]]*-?[[:space:]]*(logfile|log-directory|pidfile|trace-output)[[:space:]]*:' "${SRC}/config.yml"; then
      die "tunnel config refused: it names a file for cloudflared to write"
    fi
    UNIT_FILE="$(mktemp)"
    trap 'rm -f -- "${UNIT_FILE}"' EXIT
    cat > "${UNIT_FILE}" <<UNIT
[Unit]
Description=Laravelify Cloudflare tunnel ${TARGET}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/cloudflared tunnel --no-autoupdate --config /etc/cloudflared/laravelify-${TARGET}.yml run --token "${TOKEN}"
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    ensure_dirs /etc/cloudflared
    UNIT="laravelify-tunnel-${TARGET}.service"
    CHANGED=0
    cmp -s "${SRC}/config.yml" "/etc/cloudflared/laravelify-${TARGET}.yml" || CHANGED=1
    cmp -s "${UNIT_FILE}" "/etc/systemd/system/${UNIT}" || CHANGED=1
    write_file 644 "${SRC}/config.yml" "/etc/cloudflared/laravelify-${TARGET}.yml"
    write_file 600 "${UNIT_FILE}" "/etc/systemd/system/${UNIT}"
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
    trusted_source "${RENDERED}/services/mysql.cnf"
    ensure_dirs /etc/mysql/mysql.conf.d
    write_file 644 "${RENDERED}/services/mysql.cnf" /etc/mysql/mysql.conf.d/laravelify.cnf
    systemctl restart mysql
    ;;
  redis)
    trusted_source "${RENDERED}/services/redis.conf"
    ensure_dirs /etc/redis
    write_file 644 "${RENDERED}/services/redis.conf" /etc/redis/laravelify.conf
    if [[ -f /etc/redis/redis.conf ]] && ! grep -q '/etc/redis/laravelify.conf' /etc/redis/redis.conf; then
      printf '\ninclude /etc/redis/laravelify.conf\n' >> /etc/redis/redis.conf
    fi
    systemctl restart redis-server
    ;;
  phpmyadmin)
    trusted_source "${RENDERED}/services/phpmyadmin.conf"
    validate_nginx "${RENDERED}/services/phpmyadmin.conf"
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
  phpmyadmin-hide)
    # phpMyAdmin does not list the panel's own database (or its own storage database), and PHP's
    # deprecation notices stay out of its pages: phpMyAdmin adds them to its AJAX answers, which
    # breaks sorting rows by a column and other clicks on newer PHP versions.
    # Loaded after the other conf.d files ("zz-"), so the one-click sign-in server is covered too.
    [[ -d /etc/phpmyadmin ]] || { echo "phpMyAdmin is not installed."; exit 0; }
    panel_db="$(grep -E '^DB_DATABASE=' "${ROOT}/.env" 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"'"'"' \r')"
    panel_db="${panel_db:-laravelify}"
    [[ "${panel_db}" =~ ^[A-Za-z0-9_-]{1,64}$ ]] || die "invalid panel database name"
    ensure_dirs /etc/phpmyadmin/conf.d
    cat > /etc/phpmyadmin/conf.d/zz-laravelify-hide.php <<PHP
<?php
// Written by Laravelify (host-apply.sh phpmyadmin-hide): the panel's own database is not listed,
// and deprecation notices of newer PHP versions do not break phpMyAdmin's AJAX answers.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_STRICT);
foreach (array_keys(\$cfg['Servers'] ?? []) as \$laravelifyServer) {
    \$cfg['Servers'][\$laravelifyServer]['hide_db'] = '^(${panel_db}|phpmyadmin)\$';
}
PHP
    chmod 644 /etc/phpmyadmin/conf.d/zz-laravelify-hide.php
    echo "phpMyAdmin hides ${panel_db}."
    ;;
  php-fpm)
    version_ok "${TARGET}" || die "invalid php version"
    trusted_source "${RENDERED}/services/php-fpm-${TARGET}.conf"
    validate_fpm_pool "${RENDERED}/services/php-fpm-${TARGET}.conf"
    ensure_dirs "/etc/php/${TARGET}/fpm/pool.d"
    write_file 644 "${RENDERED}/services/php-fpm-${TARGET}.conf" "/etc/php/${TARGET}/fpm/pool.d/www.conf"
    systemctl restart "php${TARGET}-fpm"
    ;;
  mkdir-site)
    slug_ok "${TARGET}" || die "invalid slug"
    if [[ ! -e "/var/www/${TARGET}" ]]; then
      mkdir -p -- "/var/www/${TARGET}"
    fi
    [[ -d "/var/www/${TARGET}" && ! -L "/var/www/${TARGET}" ]] || die "/var/www/${TARGET} is not a folder"
    if [[ "$(id -u)" -eq 0 ]]; then
      own_site_folder "/var/www/${TARGET}"
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
      --uid="${SITE_USER}" --gid="${SITE_USER}" \
      --working-directory="${root}" \
      --property=UnsetEnvironment=yes \
      --setenv=PATH="${PATH_SAFE}" \
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
      --uid="${SITE_USER}" --gid="${SITE_USER}" \
      --working-directory="${root}" \
      --property=UnsetEnvironment=yes \
      --setenv=PATH="${PATH_SAFE}" \
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
  program-log-clear)
    # Empty one of a site's Supervisor program logs (root's files in a root-owned folder).
    slug_ok "${TARGET}" || die "invalid slug"
    [[ "${3:-}" =~ ^[A-Za-z0-9_.-]+\.log$ ]] || die "invalid log name"
    file="${SITE_LOGS}/${TARGET}/$3"
    [[ -f "${file}" && ! -L "${file}" ]] || die "no such log"
    truncate -s 0 -- "${file}"
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
    # Site PM2 apps run in the site user's PM2 daemon.
    # Usage: pm2 jlist all | pm2 restart|delete|logs <name>
    command -v pm2 >/dev/null 2>&1 || { echo "[]"; exit 0; }
    case "${TARGET}" in
      jlist)
        site_pm2 jlist
        exit 0
        ;;
      restart|delete|logs) ;;
      *) die "unknown pm2 command" ;;
    esac
    [[ "${3:-}" =~ ^[A-Za-z0-9_.:-]+$ ]] || die "invalid process name"
    case "${TARGET}" in
      restart) site_pm2 restart "$3" --update-env && site_pm2 save --force >/dev/null 2>&1 ;;
      delete) site_pm2 delete "$3" && site_pm2 save --force >/dev/null 2>&1 ;;
      logs) site_pm2 logs "$3" --nostream --lines 400 --raw ;;
    esac
    ;;
  pm2-boot)
    # Run by update.sh: every Node site that has a build runs in the site user's PM2 daemon
    # (moved out of root's daemon of older versions), the list is saved, and pm2-www-data
    # brings it back after a reboot.
    # Updating from 0.3.0-beta.39 or older runs that version's update.sh, which ends here: give
    # the panel its own user now (scripts/privileges.sh runs pm2-boot again itself).
    if ! id -u laravelify >/dev/null 2>&1 && [[ -z "${LARAVELIFY_PRIVILEGES:-}" && "$(id -u)" -eq 0 ]]; then
      exec bash "${ROOT}/scripts/privileges.sh"
    fi
    command -v pm2 >/dev/null 2>&1 || { echo "PM2 is not installed."; exit 0; }
    for config in "${PM2_APPS}"/*.config.cjs; do
      [[ -f "${config}" ]] || continue
      name="$(basename "${config}" .config.cjs)"
      slug_ok "${name}" || continue
      node_site_ready "/var/www/${name}" || continue
      [[ -f "/var/www/${name}/.laravelify-disabled" ]] && continue
      pm2_leave_root "${name}"
      if ! site_pm2 describe "${name}" >/dev/null 2>&1; then
        echo "Starting ${name} in PM2"
        site_pm2 startOrReload "${config}" --update-env >/dev/null 2>&1 || echo "warning: could not start ${name}" >&2
      fi
    done
    pm2_persist
    systemctl is-enabled --quiet "pm2-${SITE_USER}" 2>/dev/null && echo "PM2 apps are saved and start on boot (pm2-${SITE_USER})."
    ;;
  ports)
    # Read-only: listening TCP ports with the processes behind them (names need root).
    exec ss -H -lntp
    ;;
  backup-folder)
    # A folder on this server for backups (Backups → Storage → This server). Never a system
    # folder or a website folder: the panel owns it and writes backups into it.
    [[ "${TARGET}" =~ ^/[A-Za-z0-9._/-]+$ && "${TARGET}" != *..* ]] || die "invalid folder"
    case "${TARGET%/}/" in
      /var/www/*|/etc/*|/usr/*|/bin/*|/sbin/*|/lib/*|/lib64/*|/boot/*|/proc/*|/sys/*|/dev/*|/run/*|/tmp/*|/var/lib/*|/var/log/*|/root/*|"${ROOT}"/*)
        die "choose a folder outside system folders and /var/www, like /backups"
        ;;
    esac
    [[ "${TARGET%/}" =~ ^/[^/]+ ]] || die "choose a folder, not /"
    mkdir -p -- "${TARGET}"
    [[ ! -L "${TARGET%/}" ]] || die "that folder is a link"
    chown "${PANEL_USER}:${PANEL_USER}" -- "${TARGET}"
    chmod 750 -- "${TARGET}"
    echo "Backup folder ready: ${TARGET}"
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
    check_site_nginx "${SRC}"
    install_site_nginx "${TARGET}" "${SRC}"
    # pm2-boot and later updates leave a turned-off site alone.
    if [[ -d "/var/www/${TARGET}" && ! -L "/var/www/${TARGET}" ]]; then
      as_site touch -- "/var/www/${TARGET}/.laravelify-disabled" || true
    fi
    if [[ -f "/etc/supervisor/conf.d/laravelify-${TARGET}.conf" ]]; then
      rm -f "/etc/supervisor/conf.d/laravelify-${TARGET}.conf"
      supervisorctl reread || true
      supervisorctl update || true
    fi
    if command -v pm2 >/dev/null 2>&1; then
      pm2_leave_root "${TARGET}"
      if site_pm2 describe "${TARGET}" >/dev/null 2>&1; then
        site_pm2 delete "${TARGET}" || true
        pm2_persist
      fi
    fi
    nginx -t
    systemctl reload nginx
    ;;
  remove-site)
    slug_ok "${TARGET}" || die "invalid slug"
    sync_site_firewall "/etc/nginx/sites-available/laravelify-${TARGET}.conf" /dev/null
    rm -f "/etc/nginx/sites-enabled/laravelify-${TARGET}.conf" \
      "/etc/nginx/sites-available/laravelify-${TARGET}.conf" \
      "/etc/nginx/laravelify-security/${TARGET}.conf" \
      "/etc/nginx/laravelify-security/${TARGET}.htpasswd" \
      "/etc/nginx/conf.d/laravelify-security-${TARGET}.conf" \
      "/etc/supervisor/conf.d/laravelify-${TARGET}.conf" \
      "/var/log/laravelify/${TARGET}.metrics.log" \
      "${PM2_APPS}/${TARGET}.config.cjs"
    rm -rf -- "${SITE_LOGS:?}/${TARGET}"
    nginx -t && systemctl reload nginx || true
    supervisorctl reread || true
    supervisorctl update || true
    if command -v pm2 >/dev/null 2>&1; then
      pm2_leave_root "${TARGET}"
      site_pm2 delete "${TARGET}" >/dev/null 2>&1 || true
      pm2_persist
    fi
    ;;
  *)
    die "unknown action: ${ACTION}"
    ;;
esac
