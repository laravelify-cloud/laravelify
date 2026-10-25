#!/usr/bin/env bash
# Apply rendered Laravelify files onto this Linux host.
# Invoked with sudo by the panel user. Arguments are validated before any path is used.
#
# Websites run as their own user (lfy-<slug>), or www-data for sites not moved to their own user
# yet, and cannot read the panel, so nothing they can write is ever trusted here: rendered files
# come only from the panel's own storage, every nginx, Supervisor, and PHP-FPM file is checked
# before it is installed, and root never writes through a path inside a site folder (those
# writes run as the site's user, which can only touch what it already owns).
#
# Which user a site runs as is never taken from the panel: a site has its own user when its
# folder (/var/www/<slug>) belongs to lfy-<slug>. A site user cannot give its folder away.
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

# The shared user of sites that have no user of their own (and of servers not migrated yet).
SHARED_USER=www-data
# The user, group of the files, and HOME of the site being worked on (see use_site).
SITE_USER="${SHARED_USER}"
SITE_GROUP="${SHARED_USER}"
# Site folders of sites with their own user belong to this group (setgid folders): the panel and
# nginx are in it, site users never are, so sites cannot read each other's files.
READ_GROUP=laravelify-sites
# Every site's own user is in this group, and only they are: the panel may sudo to its members.
TENANT_GROUP=laravelify-tenants
# The panel's own user. Servers not migrated yet still run the panel as www-data.
if id -u laravelify >/dev/null 2>&1; then
  PANEL_USER=laravelify
else
  PANEL_USER=www-data
fi
STATE_DIR=/var/lib/laravelify
SHARED_HOME="${STATE_DIR}/home"
SITE_HOME="${SHARED_HOME}"
SITE_USERS_ROOT="${STATE_DIR}/sites"
SITES_ROOT=/var/www
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

# --- Site users ----------------------------------------------------------------------------

# New sites get their own user once scripts/privileges.sh made the groups, unless
# scripts/rollback-site-users.sh turned that off.
site_users_enabled() {
  [[ "${PANEL_USER}" != "${SHARED_USER}" && ! -f "${STATE_DIR}/.no-site-users" ]] \
    && getent group "${READ_GROUP}" >/dev/null && getent group "${TENANT_GROUP}" >/dev/null
}

# lfy-<slug>, shortened with a hash past 32 characters (SiteUser::userName builds the same name).
own_user_name() {
  local slug="$1" name="lfy-$1"
  if (( ${#name} > 32 )); then
    name="lfy-${slug:0:21}-$(printf '%s' "${slug}" | sha1sum | cut -c1-6)"
  fi
  echo "${name}"
}

# Work on a site: its own user when its folder belongs to that user, the shared user otherwise.
use_site() {
  local slug="$1" dir="${SITES_ROOT}/$1" own
  own="$(own_user_name "${slug}")"
  if [[ -d "${dir}" && ! -L "${dir}" ]] && id -u "${own}" >/dev/null 2>&1 \
    && [[ "$(stat -c '%U' -- "${dir}")" == "${own}" ]]; then
    SITE_USER="${own}"
    SITE_GROUP="${READ_GROUP}"
    SITE_HOME="${SITE_USERS_ROOT}/${slug}/home"
  else
    SITE_USER="${SHARED_USER}"
    SITE_GROUP="${SHARED_USER}"
    SITE_HOME="${SHARED_HOME}"
  fi
}

site_has_own_user() {
  [[ "${SITE_USER}" != "${SHARED_USER}" ]]
}

# nginx's workers: the only ones besides a site's own user that may open its PHP-FPM socket.
web_group() {
  local user
  user="$(awk '/^[[:space:]]*user[[:space:]]+/ { gsub(/;/, "", $2); print $2; exit }' /etc/nginx/nginx.conf 2>/dev/null || true)"
  [[ -n "${user}" ]] && id -u "${user}" >/dev/null 2>&1 || user=laravelify-web
  id -gn "${user}" 2>/dev/null || echo "${SHARED_USER}"
}

# A site's own user and its folders in /var/lib/laravelify/sites/<slug> (root-owned, so the site
# cannot swap its home or trash for a link): home (HOME, Composer/npm caches, PM2), trash (the
# file manager's, readable by the panel), and tmp (PHP uploads and sessions, deploy temp files).
ensure_site_user() {
  local slug="$1" user base group
  user="$(own_user_name "${slug}")"
  base="${SITE_USERS_ROOT}/${slug}"
  if ! id -u "${user}" >/dev/null 2>&1; then
    echo "Creating the user ${user} for ${slug}"
    useradd --system --user-group --home-dir "${base}/home" --no-create-home \
      --shell /usr/sbin/nologin --comment "Laravelify website ${slug}" "${user}"
  fi
  # A site user in one of these groups could read other sites or the panel.
  for group in $(id -nG "${user}"); do
    case "${group}" in
      "${user}"|"${TENANT_GROUP}") ;;
      *) die "${user} is in the group ${group}; remove it (gpasswd -d ${user} ${group})" ;;
    esac
  done
  if ! id -nG "${user}" | tr ' ' '\n' | grep -qx "${TENANT_GROUP}"; then
    usermod -aG "${TENANT_GROUP}" "${user}"
  fi
  install -d -o root -g root -m 0755 "${STATE_DIR}" "${SITE_USERS_ROOT}" "${base}"
  install -d -o "${user}" -g "${user}" -m 0700 "${base}/home" "${base}/tmp"
  install -d -o "${user}" -g "${READ_GROUP}" -m 2750 "${base}/trash"
  # That mode resets the ACLs' mask: workspaces with this site's project get their way in back.
  restore_workspace_access "${slug}" "${base}/home"
}

# restore_workspace_access <slug> <site home>
restore_workspace_access() {
  local slug="$1" home="$2" list key
  command -v setfacl >/dev/null 2>&1 || return 0
  for list in "${STATE_DIR}"/workspaces/*/sites; do
    [[ -f "${list}" ]] && grep -qx "${slug}" "${list}" || continue
    key="$(basename "$(dirname "${list}")")"
    [[ -d "${home}/workspaces" ]] || continue
    setfacl -m "u:lfy-ws--${key}:--x" -- "${home}" "${home}/workspaces" 2>/dev/null || true
  done
}

# Give a site folder to its user. chown -R -P never follows links. Folders get the setgid bit,
# so new files take the folder's group (the site user cannot set it: it is not in that group).
own_site_folder() {
  local site_dir="$1"
  [[ -d "${site_dir}" && ! -L "${site_dir}" ]] || return 0
  chown -R -P -h "${SITE_USER}:${SITE_GROUP}" -- "${site_dir}" || true
  if site_has_own_user; then
    chmod 2750 -- "${site_dir}"
    setgid_folders "${site_dir}"
  fi
}

# --- VS Code ---------------------------------------------------------------------------------

# nginx for one editor at /code/<token>/ (a website's or a workspace's): only through the panel's
# check (/_access/code), the panel's phone layout added, a stopped editor sent to its launcher.
# write_code_editor_conf <conf file> <token> <port> <systemd unit, stopped when nginx refuses>
write_code_editor_conf() {
  local CONF="$1" TOKEN="$2" PORT="$3" UNIT="$4" PANEL_PUBLIC ASSET_VERSION MEDIA ICONS="" pair
  # The editor's tab icon and install (PWA) icons are the panel's, not code-server's.
  PANEL_PUBLIC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/public"
  # Phones fetch the phone layout files again after every panel update.
  ASSET_VERSION="$(tr -cd '0-9A-Za-z.-' < "${PANEL_PUBLIC}/../VERSION" 2>/dev/null || true)-$(cat "${PANEL_PUBLIC}/code-assets/phone.css" "${PANEL_PUBLIC}/code-assets/phone.js" 2>/dev/null | cksum | cut -d' ' -f1)"
  MEDIA="/code/${TOKEN}/_static/src/browser/media"
  for pair in favicon.ico:favicon.ico favicon.svg:favicon.svg favicon-dark-support.svg:favicon.svg \
    pwa-icon-192.png:android-chrome-192x192.png pwa-icon-512.png:android-chrome-512x512.png \
    pwa-icon-maskable-192.png:android-chrome-192x192.png pwa-icon-maskable-512.png:maskable-icon-512x512.png; do
    if [[ -f "${PANEL_PUBLIC}/${pair#*:}" ]]; then
      ICONS+="location = ${MEDIA}/${pair%%:*} { alias ${PANEL_PUBLIC}/${pair#*:}; add_header Cache-Control \"public, max-age=86400\"; }"$'\n'
    fi
  done
  cat > "${CONF}" <<EOF
${ICONS}
location /code/${TOKEN}/ {
    auth_request /_laravelify/code-auth;
    error_page 401 = @laravelify_code_login;
    # VS Code stopped by itself (30 minutes idle): its launcher starts it again, instead of a 502.
    error_page 502 504 = @laravelify_code_down_${TOKEN};
    proxy_pass http://127.0.0.1:${PORT}/;
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_read_timeout 86400;
    proxy_send_timeout 86400;
    client_max_body_size 256M;
    # Cloudflare (Web Analytics, Rocket Loader) must not inject scripts into VS Code's pages and
    # webviews: their content security policy blocks them and the console fills with errors.
    add_header Cache-Control "no-transform" always;
    # Phones and tablets: zoom allowed, and the panel's phone layout (bigger touch targets, one
    # part at a time). The page comes uncompressed from code-server so nginx can add them.
    proxy_set_header Accept-Encoding "";
    sub_filter_once on;
    # Only the workbench page has this viewport tag (webviews do not), so only it gets the files.
    sub_filter '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">' '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><link rel="stylesheet" href="/code/${TOKEN}/__laravelify/phone.css?v=${ASSET_VERSION}"><script src="/code/${TOKEN}/__laravelify/phone.js?v=${ASSET_VERSION}"></script>';
}
location = /code/${TOKEN}/__laravelify/phone.css { alias ${PANEL_PUBLIC}/code-assets/phone.css; add_header Cache-Control "public, max-age=3600"; }
location = /code/${TOKEN}/__laravelify/phone.js { alias ${PANEL_PUBLIC}/code-assets/phone.js; add_header Cache-Control "public, max-age=3600"; }
location @laravelify_code_down_${TOKEN} {
    return 302 /code-restart/${TOKEN};
}
EOF
  if ! nginx -t 2>/dev/null; then
    rm -f "${CONF}"
    systemctl stop "${UNIT}" >/dev/null 2>&1 || true
    die "nginx rejected the editor location"
  fi
  systemctl reload nginx
}

# Workspaces: VS Code for several websites' projects at once, as their own user lfy-ws--<key>
# (a website's slug never has "--", so the name can never be a website's user). Their homes are
# in /var/lib/laravelify/workspaces/<key>/home. They are in laravelify-tenants: the panel may sudo
# to them, as to the websites' users.
WORKSPACES_ROOT="${STATE_DIR}/workspaces"

workspace_key_ok() {
  [[ "$1" =~ ^[a-z0-9]{6,16}$ ]]
}

workspace_user_name() {
  echo "lfy-ws--$1"
}

ensure_workspace_user() {
  local key="$1" user base
  user="$(workspace_user_name "${key}")"
  base="${WORKSPACES_ROOT}/${key}"
  if ! id -u "${user}" >/dev/null 2>&1; then
    useradd --system --user-group --home-dir "${base}/home" --no-create-home \
      --shell /usr/sbin/nologin --comment "Laravelify VS Code workspace ${key}" "${user}"
  fi
  for group in $(id -nG "${user}"); do
    case "${group}" in
      "${user}"|"${TENANT_GROUP}") ;;
      *) die "${user} is in the group ${group}; remove it (gpasswd -d ${user} ${group})" ;;
    esac
  done
  id -nG "${user}" | tr ' ' '\n' | grep -qx "${TENANT_GROUP}" || usermod -aG "${TENANT_GROUP}" "${user}"
  install -d -o root -g root -m 0755 "${WORKSPACES_ROOT}" "${base}"
  install -d -o "${user}" -g "${user}" -m 0700 "${base}/home"
}

# A workspace's user may pass through a website's home (nothing else there: its sign-ins, caches,
# and keys stay closed) into the project's folder, where it reads and writes everything. Default
# ACLs keep it that way for new files, for the workspace and for the website's own user alike.
# grant_workspace_access <workspace user> <site user> <site home> <project folder>
grant_workspace_access() {
  local ws="$1" site="$2" home="$3" dir="$4"
  command -v setfacl >/dev/null 2>&1 || die "setfacl is not installed: sudo apt-get install -y acl"
  setfacl -m "u:${ws}:--x" -- "${home}" "${home}/workspaces"
  setfacl -R -P -m "u:${ws}:rwX,d:u:${ws}:rwX,d:u:${site}:rwX,m::rwx,d:m::rwx" -- "${dir}"
}

# revoke_workspace_access <workspace user> <site home> <project folder>
revoke_workspace_access() {
  local ws="$1" home="$2" dir="$3"
  command -v setfacl >/dev/null 2>&1 || return 0
  [[ -d "${dir}" && ! -L "${dir}" ]] && setfacl -R -P -x "u:${ws},d:u:${ws}" -- "${dir}" 2>/dev/null || true
  setfacl -x "u:${ws}" -- "${home}/workspaces" "${home}" 2>/dev/null || true
}

# Set the setgid bit on every folder below $1 as root without following a link the site may swap
# in: find enters each folder itself (-execdir), and perl opens the name with O_NOFOLLOW and
# changes the folder it opened (fchmod). Files never get the bit.
#
# Installed packages, builds, caches, and git's own folder are skipped (node_modules alone can hold
# tens of thousands of folders, one perl each): nothing there is served or edited, and their files
# are readable anyway. So are folders the site's .gitignore names (plain names, like "dist/").
setgid_folders() {
  local skip=(node_modules vendor .git .next .nuxt .svelte-kit .turbo .cache .output .vercel bower_components)
  local ignore name args=()
  while IFS= read -r ignore; do
    while IFS= read -r name; do
      name="${name%%#*}"; name="${name%/}"; name="${name#/}"; name="${name//[[:space:]]/}"
      [[ "${name}" =~ ^[A-Za-z0-9._-]+$ && "${name}" != "." && "${name}" != ".." ]] && skip+=("${name}")
    done < "${ignore}"
  done < <(find "$1" -maxdepth 3 -name .gitignore -type f 2>/dev/null)
  for name in "${skip[@]}"; do
    args+=(-name "${name}" -o)
  done
  unset 'args[${#args[@]}-1]'
  find "$1" -xdev -mindepth 1 -type d \( "${args[@]}" \) -prune -o -type d ! -perm -2000 -execdir perl -MFcntl -e '
    for my $name (@ARGV) {
      sysopen(my $folder, $name, O_RDONLY | O_DIRECTORY | O_NOFOLLOW) or next;
      chmod(((stat $folder)[2] & 07777) | 02000, $folder);
    }
  ' {} + 2>/dev/null || true
}

# The site folder of a new site: owned by its own user when sites get their own users.
create_site_folder() {
  local slug="$1" dir="${SITES_ROOT}/$1"
  if [[ ! -e "${dir}" ]]; then
    if site_users_enabled; then
      ensure_site_user "${slug}"
      install -d -o "$(own_user_name "${slug}")" -g "${READ_GROUP}" -m 2750 "${dir}"
    else
      mkdir -p -- "${dir}"
    fi
  fi
  [[ -d "${dir}" && ! -L "${dir}" ]] || die "${dir} is not a folder"
  use_site "${slug}"
}

# --- GlitchTip's nginx locations ------------------------------------------------------------
# GlitchTip at /glitchtip on the panel's address. Its pages need a GlitchTip session: a page
# opened without one (or with an expired one) goes to /tools/glitchtip, which needs the panel's
# sign-in and signs GlitchTip in, so GlitchTip's own sign-in page does not show. Its API, the
# errors the websites send, sign-in calls, and files (scripts, styles) pass straight through.
# ?signin=own on /glitchtip/login shows GlitchTip's own sign-in: the panel sends the browser
# there when its sign-in was turned down (the password was changed in GlitchTip).
write_glitchtip_nginx() {
  install -d -m 0755 /etc/nginx/laravelify-panel.d
  local conf=/etc/nginx/laravelify-panel.d/50-glitchtip.conf previous=""
  [[ -f "${conf}" ]] && previous="$(cat "${conf}")"
  cat > "${conf}" <<'EOF'
# Written by Laravelify (host-apply.sh glitchtip-nginx): GlitchTip, signed in through the panel.
location = /glitchtip { return 301 /glitchtip/; }

location = /glitchtip-session {
    internal;
    proxy_pass http://127.0.0.1:8095/glitchtip/_allauth/browser/v1/auth/session;
    proxy_pass_request_body off;
    proxy_set_header Content-Length "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
}

location @glitchtip_signin {
    if ($uri ~ "^/glitchtip/([A-Za-z0-9][A-Za-z0-9/_.-]*)$") {
        return 302 /tools/glitchtip?to=$1;
    }
    return 302 /tools/glitchtip;
}

location ^~ /glitchtip/ {
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Host $host;
    proxy_read_timeout 60s;
    client_max_body_size 40m;

    # The panel manages the organization, its projects and keys, the admin's password and MFA,
    # and its API token: browsers may not delete or change them (the panel talks to GlitchTip
    # directly, not through here). auth_request below is inherited by these nested locations,
    # so each that skips the session check turns it off.
    location ~ "^/glitchtip/(api/0/(organizations/[^/]+|projects/[^/]+/[^/]+(/keys/[^/]+)?|api-tokens/[^/]+|users/[^/]+(/emails)?)/$|api/0/generate-recovery-codes/|_allauth/browser/v1/account/(password|authenticators))" {
        auth_request off;
        if ($request_method !~ ^(GET|HEAD|OPTIONS)$) {
            add_header Content-Type application/json always;
            return 403 '{"detail":"Laravelify manages this: change it in the panel."}';
        }
        proxy_pass http://127.0.0.1:8095;
    }

    # The API (errors from the websites), sign-in calls, and other endpoints.
    location ~ ^/glitchtip/(api|_allauth|accounts|static|_health|v1|oauth|accept|admin|stripe|metrics)(/|$) {
        auth_request off;
        proxy_pass http://127.0.0.1:8095;
    }

    # Files: scripts, styles, icons.
    location ~ \.[A-Za-z0-9]+$ {
        auth_request off;
        proxy_pass http://127.0.0.1:8095;
    }

    location = /glitchtip/login {
        auth_request off;
        if ($arg_signin != "own") {
            return 302 /tools/glitchtip;
        }
        proxy_pass http://127.0.0.1:8095;
    }

    # Pages: only with a GlitchTip session, with the panel's additions (public/glitchtip-panel).
    auth_request /glitchtip-session;
    # Only 401 (no session): a 403 is the answer above, which must reach the browser.
    error_page 401 = @glitchtip_signin;
    proxy_set_header Accept-Encoding "";
    sub_filter_once on;
    sub_filter '</head>' '<link rel="stylesheet" href="/glitchtip-panel/glitchtip.css?v=__VERSION__"><script src="/glitchtip-panel/glitchtip.js?v=__VERSION__" defer></script></head>';
    proxy_pass http://127.0.0.1:8095;
}
EOF
  # A new version of the additions is loaded at once, not from the browser's cache.
  local version
  version="$(cat "${ROOT}/public/glitchtip-panel/glitchtip.js" "${ROOT}/public/glitchtip-panel/glitchtip.css" 2>/dev/null | md5sum | cut -c1-10)"
  sed -i "s/__VERSION__/${version}/g" "${conf}"
  if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx
  else
    if [[ -n "${previous}" ]]; then printf '%s\n' "${previous}" > "${conf}"; else rm -f "${conf}"; fi
    nginx -t
    die "nginx did not accept GlitchTip's locations, so the previous ones were put back"
  fi
}

# --- A site's PHP-FPM pool -----------------------------------------------------------------
# Written here from fixed values, never from the panel: the FPM master runs as root.

site_pool_file() {
  echo "/etc/php/$2/fpm/pool.d/laravelify-site-$1.conf"
}

reload_fpm() {
  # The panel itself may run on this PHP-FPM, inside the very request that called us. The reload
  # runs 2 seconds later, after that request has answered (waiting for it here would wait for our
  # own request), and lets running requests finish (process_control_timeout; 0 kills them).
  local conf="/etc/php/$1/fpm/php-fpm.conf"
  if [[ -f "${conf}" ]] && ! grep -qE '^process_control_timeout\s*=\s*[1-9]' "${conf}"; then
    sed -i -E '/^;?process_control_timeout\s*=/d' "${conf}"
    sed -i -E 's/^\[global\]$/[global]\nprocess_control_timeout = 30s/' "${conf}"
  fi
  systemd-run --quiet --collect --on-active=2s --timer-property=AccuracySec=100ms \
      systemctl reload "php$1-fpm" >/dev/null 2>&1 \
    || systemctl --no-block reload "php$1-fpm" >/dev/null 2>&1 \
    || systemctl --no-block restart "php$1-fpm"
}

write_site_pool() {
  local slug="$1" version="$2" file tmp base other old_version
  version_ok "${version}" || die "invalid php version"
  [[ -x "/usr/sbin/php-fpm${version}" ]] || die "PHP ${version}-FPM is not installed"
  file="$(site_pool_file "${slug}" "${version}")"
  base="${SITE_USERS_ROOT}/${slug}"
  tmp="$(mktemp)"
  cat > "${tmp}" <<POOL
; Written by Laravelify (host-apply.sh) for the website ${slug}. Saving the website writes it again.
; Only ${SITE_USER} runs here, and only nginx may open the socket.
[site-${slug}]
user = ${SITE_USER}
group = ${SITE_USER}
listen = /run/php/site-${slug}.sock
listen.owner = ${SITE_USER}
listen.group = $(web_group)
listen.mode = 0660
pm = ondemand
pm.max_children = 10
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[upload_tmp_dir] = ${base}/tmp
php_admin_value[sys_temp_dir] = ${base}/tmp
php_value[session.save_path] = ${base}/tmp
env[TMPDIR] = ${base}/tmp
POOL
  if ! cmp -s "${tmp}" "${file}"; then
    ensure_dirs "/etc/php/${version}/fpm/pool.d"
    write_file 644 "${tmp}" "${file}"
    if ! "/usr/sbin/php-fpm${version}" -t >/dev/null 2>&1; then
      rm -f -- "${file}" "${tmp}"
      die "php-fpm${version} rejected the pool of ${slug}"
    fi
    reload_fpm "${version}"
  elif [[ ! -S "/run/php/site-${slug}.sock" ]]; then
    reload_fpm "${version}"
  fi
  rm -f -- "${tmp}"
  # The pool of the PHP version the site used before goes away.
  for other in /etc/php/*/fpm/pool.d/laravelify-site-"${slug}".conf; do
    [[ -f "${other}" && "${other}" != "${file}" ]] || continue
    old_version="${other#/etc/php/}"
    old_version="${old_version%%/*}"
    rm -f -- "${other}"
    reload_fpm "${old_version}" || true
  done
  # The pool passed php-fpm -t above; its socket appears when the delayed reload runs, after
  # this request answers (waiting for it here would hold up that reload: see reload_fpm).
  return 0
}

remove_site_pool() {
  local slug="$1" file version
  for file in /etc/php/*/fpm/pool.d/laravelify-site-"${slug}".conf; do
    [[ -f "${file}" ]] || continue
    version="${file#/etc/php/}"
    version="${version%%/*}"
    rm -f -- "${file}"
    reload_fpm "${version}" || true
  done
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
# paths into a link, and root would follow it; the site user can only reach what it already owns.
as_site() {
  runuser -u "${SITE_USER}" -- env -i PATH="${PATH_SAFE}" HOME="${SITE_HOME}" LANG=C.UTF-8 "$@"
}

# Copy a panel file into a site folder as the site user (root reads the source, the site user writes).
write_site_file() {
  local mode="$1" src="$2" dest="$3"
  as_site /bin/sh -c 'set -e; t="$1.laravelify-$$"; cat > "$t"; chmod "$2" -- "$t"; mv -f -T -- "$t" "$1"' sh "${dest}" "${mode}" < "${src}"
}

# Rendered files must come from the panel: owned by root or the panel user, and writable by
# nobody else. Website code cannot write the panel's storage once the panel has its own user.
trusted_source() {
  local path="$1" owner mode
  [[ -e "${path}" && ! -L "${path}" ]] || die "missing ${path#"${ROOT}"/}"
  while [[ "${path}" != "${ROOT}" && "${path}" != / ]]; do
    [[ -L "${path}" ]] && die "${path#"${ROOT}"/} is a link"
    owner="$(stat -c '%U' -- "${path}")"
    mode="$(stat -c '%a' -- "${path}")"
    [[ "${owner}" == root || "${owner}" == "${PANEL_USER}" ]] || die "${path#"${ROOT}"/} is not owned by the panel (${owner})"
    # Group or other write (the middle and last octal digit carry 2).
    if (( (8#${mode} & 8#022) != 0 )) && [[ "${PANEL_USER}" != "${SHARED_USER}" ]]; then
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
  local file="$1" socket="" allow_panel=0 reasons
  # A site with its own user only talks to its own pool; never another site's or the panel's.
  # phpMyAdmin is the exception: it runs in the panel's pool (pass "panel" as the second argument).
  if [[ "${2:-}" == "panel" ]]; then
    allow_panel=1
  elif site_has_own_user; then
    socket="unix:/run/php/site-${SLUG_IN_CHECK}.sock"
  fi
  # The reasons go into the error itself: the panel shows only its last line.
  reasons="$(awk -v socket="${socket}" -v allow_panel="${allow_panel}" '
    function fail(message) { if (!(message in said)) { said[message] = 1; printf "%s%s", (bad ? "; " : ""), message } bad = 1 }
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
      } else if (directive == "fastcgi_pass" && value ~ /^unix:/) {
        if (socket != "" && value != socket) {
          fail("fastcgi_pass " value ": a site with its own user runs PHP only in its own pool (" socket "); send paths of another site to it with proxy_pass http://127.0.0.1 and its Host instead")
        } else if (socket == "" && value !~ /^unix:\/run\/php\/php8\.[0-9]+-fpm\.sock$/ && !(allow_panel == 1 && value == "unix:/run/php/laravelify.sock")) {
          fail("fastcgi_pass " value ": use the PHP-FPM socket of a PHP version")
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
  ' "${file}")" || die "nginx config refused (${file##*/}): ${reasons}"
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
# Node sites run in their site user's PM2 daemon (PM2_HOME in that user's home), restored on
# boot by pm2-<user>: one daemon per site with its own user, one for the shared www-data.
# PM2 files are JavaScript that PM2 runs, so they live in a root-owned folder, never in the site
# folder. Older versions ran apps in root's daemon.

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
    if site_has_own_user; then
      as_site mkdir -p -m 0700 -- "${SITE_HOME}/.pm2"
    else
      install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0750 "${SITE_HOME}" "${SITE_HOME}/.pm2"
    fi
  fi
}

# A PM2 daemon is running for this PM2_HOME (pm2 commands would start one otherwise).
pm2_running() {
  [[ -S "$1/rpc.sock" ]]
}

# The shared user's daemon, for sites moving to their own user.
shared_pm2() {
  runuser -u "${SHARED_USER}" -- env -i PATH="${PATH_SAFE}" HOME="${SHARED_HOME}" LANG=C.UTF-8 PM2_HOME="${SHARED_HOME}/.pm2" pm2 "$@"
}

# Save the site daemon's list and make sure systemd restores it on boot.
pm2_persist() {
  site_pm2 save --force >/dev/null 2>&1 || echo "warning: pm2 save failed" >&2
  if ! systemctl is-enabled --quiet "pm2-${SITE_USER}" 2>/dev/null; then
    env PATH="${PATH_SAFE}" pm2 startup systemd -u "${SITE_USER}" --hp "${SITE_HOME}" >/dev/null 2>&1 \
      || echo "warning: could not enable PM2 on boot (pm2-${SITE_USER})" >&2
  fi
}

# Stop an app of older versions in root's daemon (and the stray /etc/.pm2 one), or in the shared
# daemon once the site has its own user, so the site's daemon can take its port.
pm2_leave_root() {
  local name="$1" changed=0
  if site_has_own_user && pm2_running "${SHARED_HOME}/.pm2" && shared_pm2 describe "${name}" >/dev/null 2>&1; then
    echo "Moving ${name} from ${SHARED_USER}'s PM2 to ${SITE_USER}'s PM2"
    shared_pm2 delete "${name}" >/dev/null 2>&1 || true
    shared_pm2 save --force >/dev/null 2>&1 || true
  fi
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
  local output config="${PM2_APPS}/${slug}.config.cjs" applied="${PM2_APPS}/${slug}.start"
  local start
  pm2_leave_root "${slug}"
  # startOrReload keeps a running app's old script, interpreter, cwd and args (it only takes
  # the new env), so an app whose way of starting changed would run its old command. Those
  # lines are kept from the last start: when they differ (or are not known yet), the app is
  # removed from PM2 and started from its file.
  start="$(grep -E '^[[:space:]]*(cwd|script|args|interpreter):' "${config}" 2>/dev/null || true)"
  if site_pm2 describe "${slug}" >/dev/null 2>&1 && [[ "${start}" != "$(cat "${applied}" 2>/dev/null)" ]]; then
    site_pm2 delete "${slug}" >/dev/null 2>&1 || true
  fi
  # PM2 prints its whole process table; keep only what went wrong.
  if output="$(site_pm2 startOrReload "${config}" --update-env 2>&1)"; then
    printf '%s' "${start}" > "${applied}"
    echo "PM2: ${slug} started as ${SITE_USER}."
  else
    echo "${output}" | grep -vE '[│┌├└]' >&2
    die "PM2 could not start ${slug}"
  fi
  pm2_persist
}

pm2_install_config() {
  local slug="$1" src="$2"
  install -d -o root -g root -m 0755 "${STATE_DIR}" "${PM2_APPS}"
  write_file 640 "${src}" "${PM2_APPS}/${slug}.config.cjs"
  chown "root:${SITE_USER}" "${PM2_APPS}/${slug}.config.cjs"
}

# Every PM2 app of every daemon (the shared one and each site's), as one JSON list.
pm2_jlist_all() {
  local out dir user
  out="$(mktemp -d)"
  if pm2_running "${SHARED_HOME}/.pm2"; then
    shared_pm2 jlist > "${out}/shared.json" 2>/dev/null || true
  fi
  for dir in "${SITE_USERS_ROOT}"/*/home/.pm2; do
    [[ -d "${dir}" && ! -L "${dir}" ]] && pm2_running "${dir}" || continue
    user="$(stat -c '%U' -- "${dir}")"
    [[ "${user}" == lfy-* ]] || continue
    runuser -u "${user}" -- env -i PATH="${PATH_SAFE}" HOME="${dir%/.pm2}" LANG=C.UTF-8 PM2_HOME="${dir}" pm2 jlist > "${out}/${user}.json" 2>/dev/null || true
  done
  node -e '
    const fs = require("fs");
    let all = [];
    for (const file of process.argv.slice(1)) {
      const text = fs.readFileSync(file, "utf8");
      const start = text.indexOf("[");
      if (start < 0) continue;
      try { all = all.concat(JSON.parse(text.slice(start))); } catch (error) {}
    }
    process.stdout.write(JSON.stringify(all));
  ' "${out}"/*.json 2>/dev/null || echo "[]"
  rm -rf -- "${out}"
}

# Stop a site's own PM2 daemon for good (the site goes back to the shared user, or is removed).
pm2_drop_daemon() {
  local unit="pm2-${SITE_USER}"
  if pm2_running "${SITE_HOME}/.pm2"; then
    site_pm2 kill >/dev/null 2>&1 || true
  fi
  systemctl disable --now "${unit}" >/dev/null 2>&1 || true
  rm -f "/etc/systemd/system/${unit}.service"
  systemctl daemon-reload >/dev/null 2>&1 || true
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
  SLUG_IN_CHECK="$(basename "${src}")"
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
    chown "root:$(web_group)" "${sec_dir}/${slug}.htpasswd" || true
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

# Give a site back to the shared user: its files, trash, and PM2 app; its own pool, daemon, and
# user go away. Its Supervisor programs stop until the panel writes them again for www-data.
drop_site_user() {
  local slug="$1" dir="${SITES_ROOT}/$1" own base item program
  use_site "${slug}"
  site_has_own_user || return 0
  own="${SITE_USER}"
  base="${SITE_USERS_ROOT}/${slug}"
  echo "Giving ${slug} back to ${SHARED_USER} (removing ${own})"
  for program in $(supervisorctl status 2>/dev/null | awk '{print $1}' | grep -E "^${slug}-" || true); do
    supervisorctl stop "${program}" >/dev/null 2>&1 || true
  done
  if command -v pm2 >/dev/null 2>&1; then
    pm2_drop_daemon
  fi
  remove_site_pool "${slug}"
  pkill -u "${own}" >/dev/null 2>&1 || true
  sleep 1
  pkill -9 -u "${own}" >/dev/null 2>&1 || true
  chown -R -P -h "${SHARED_USER}:${SHARED_USER}" -- "${dir}" || true
  chmod 0755 -- "${dir}"
  runuser -u "${SHARED_USER}" -- find "${dir}" -xdev -type d -perm -2000 -exec chmod g-s -- {} + 2>/dev/null || true
  if [[ -d "${base}/trash" && ! -L "${base}/trash" ]]; then
    install -d -o "${SHARED_USER}" -g "${SHARED_USER}" -m 0750 "${STATE_DIR}/trash"
    for item in "${base}/trash"/*; do
      [[ -e "${item}" || -L "${item}" ]] || continue
      if [[ ! -e "${STATE_DIR}/trash/$(basename "${item}")" ]]; then
        mv -T -- "${item}" "${STATE_DIR}/trash/$(basename "${item}")"
        chown -R -P -h "${SHARED_USER}:${SHARED_USER}" -- "${STATE_DIR}/trash/$(basename "${item}")"
      fi
    done
  fi
  userdel "${own}" >/dev/null 2>&1 || echo "warning: could not delete the user ${own}" >&2
  getent group "${own}" >/dev/null && groupdel "${own}" >/dev/null 2>&1 || true
  rm -rf -- "${base}"
  if [[ -f "${PM2_APPS}/${slug}.config.cjs" ]]; then
    chown "root:${SHARED_USER}" "${PM2_APPS}/${slug}.config.cjs"
  fi
  use_site "${slug}"
}

# "run" and "run-global" take a fourth word, "pty", for the panel's interactive terminal: the command
# gets a real terminal (it can ask a question and read the answer) and more time to wait for one.
IO_MODE=--pipe
MAX_SECONDS=60
if [[ "${4:-}" == pty ]]; then
  IO_MODE=--pty
  MAX_SECONDS=900
elif [[ "${4:-}" == pty-shell ]]; then
  # The code editor's terminal: a shell (or Claude Code / Cursor Agent) the person works in.
  IO_MODE=--pty
  MAX_SECONDS=14400
fi

# The panel runs several deploys and tasks at once. Actions that write nginx, Supervisor, PM2,
# PHP pools, the tunnel, or users take turns (each takes a second or two), so one never tests or
# reloads nginx while another is halfway through its files. Long or read-only actions (commands,
# logs, status, backups, restores, patches, zips, the code-server install) do not wait.
case "${ACTION}" in
  site|site-processes|site-nginx|site-disable|remove-site|mkdir-site|site-user|site-user-drop|\
  panel-preview|panel-preview-remove|code-server-start|code-server-stop|\
  workspace-start|workspace-stop|workspace-drop|cloudflared|remove-tunnel|pm2-boot|\
  phpmyadmin|phpmyadmin-sso|phpmyadmin-hide|glitchtip-nginx|glitchtip-remove)
    exec 9>/run/laravelify-host-apply.lock
    flock -w 300 9 || die "another server change has been running for 5 minutes; try again"
    ;;
esac

case "${ACTION}" in
  site)
    slug_ok "${TARGET}" || die "invalid slug"
    KIND="${3:-}"
    RUNTIME="$(site_runtime "${KIND}")"
    PHP_VERSION="${4:-8.4}"
    version_ok "${PHP_VERSION}" || die "invalid php version"
    SRC="${RENDERED}/sites/${TARGET}"
    SITE_DIR="/var/www/${TARGET}"
    [[ -f "${SRC}/nginx.conf" ]] || die "missing rendered nginx config"
    # The folder (and a new site's own user) first: the checks below depend on the site's user.
    create_site_folder "${TARGET}"
    # Check everything before anything else is written: a refused file leaves the live site as it is.
    check_site_nginx "${SRC}"
    if [[ "${KIND}" == "laravel" ]]; then
      trusted_source "${SRC}/process.conf"
      validate_supervisor "${SRC}/process.conf" "${TARGET}"
    fi
    [[ -f "${SRC}/.env" ]] && trusted_source "${SRC}/.env"

    ensure_dirs /etc/nginx/sites-available /etc/nginx/sites-enabled
    own_site_folder "${SITE_DIR}"
    # PHP runs in the site's own PHP-FPM pool once it has its own user.
    if [[ "${RUNTIME}" == php ]] && site_has_own_user; then
      write_site_pool "${TARGET}" "${PHP_VERSION}"
    fi
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
    use_site "${TARGET}"
    own_site_folder "/var/www/${TARGET}"
    apply_site_processes "${TARGET}" "${KIND}" "${SRC}"
    ;;
  site-nginx)
    # Security rules, blocked IPs, and the request log, without touching workers.
    slug_ok "${TARGET}" || die "invalid slug"
    SRC="${RENDERED}/sites/${TARGET}"
    [[ -f "${SRC}/nginx.conf" ]] || die "missing rendered nginx config"
    use_site "${TARGET}"
    check_site_nginx "${SRC}"
    install_site_nginx "${TARGET}" "${SRC}"
    nginx -t
    systemctl reload nginx
    ;;
  panel-preview)
    # A site opened through the panel's own address, at /p/<token>/. Built here from checked
    # arguments, never from a rendered file: panel-preview <slug> <token> <port> <host>.
    slug_ok "${TARGET}" || die "invalid slug"
    TOKEN="${3:-}"; UPSTREAM_PORT="${4:-}"; UPSTREAM_HOST="${5:-}"
    [[ "${TOKEN}" =~ ^[a-z0-9]{32}$ ]] || die "invalid preview token"
    [[ "${UPSTREAM_PORT}" =~ ^[0-9]{2,5}$ ]] && (( UPSTREAM_PORT >= 80 && UPSTREAM_PORT <= 65535 )) || die "invalid port"
    [[ "${UPSTREAM_HOST}" =~ ^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$ ]] || die "invalid host"
    PREVIEW_DIR=/etc/nginx/laravelify-panel.d
    install -d -m 0755 "${PREVIEW_DIR}"
    # Apps build some paths at run time ("/login" in JavaScript, fetch("/api/…")), which leave
    # /p/<token>/. A request from a preview page (its Referer) to any other panel path is sent
    # back into the same preview; 307 keeps the method and body of form posts and API calls.
    cat > /etc/nginx/conf.d/laravelify-preview-referer.conf <<'EOF'
map $http_referer $laravelify_preview_from {
    "~^https?://[^/]+/p/([a-z0-9]{32})/" $1;
    default "";
}
map "$laravelify_preview_from:$uri" $laravelify_preview_back {
    "~^([a-z0-9]{32}):(?!/p/)" /p/$1;
    default "";
}
EOF
    cat > "${PREVIEW_DIR}/00-preview-referer.conf" <<'EOF'
# Redirects keep the scheme the browser used (the tunnel reaches nginx over plain HTTP).
absolute_redirect off;
if ($laravelify_preview_back) {
    return 307 $laravelify_preview_back$request_uri;
}
EOF
    PREVIEW_FILE="${PREVIEW_DIR}/preview-${TARGET}.conf"
    PREVIEW_OLD="$(mktemp)"
    [[ -f "${PREVIEW_FILE}" ]] && cp "${PREVIEW_FILE}" "${PREVIEW_OLD}"
    HOST_PATTERN="${UPSTREAM_HOST//./\\.}"
    # Apps build links and redirects for their own domain and for paths from "/": both are
    # rewritten into /p/<token>/ so the browser stays in the preview (redirects, cookies, and
    # the URLs in HTML, CSS and JavaScript responses).
    cat > "${PREVIEW_FILE}" <<EOF
location /p/${TOKEN}/ {
    proxy_pass http://127.0.0.1:${UPSTREAM_PORT}/;
    proxy_http_version 1.1;
    proxy_set_header Host ${UPSTREAM_HOST};
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    proxy_set_header X-Forwarded-Prefix /p/${TOKEN};
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Accept-Encoding "";
    proxy_read_timeout 120;
    # The return trip above needs the page's full address in the Referer.
    proxy_hide_header Referrer-Policy;
    add_header Referrer-Policy "same-origin" always;

    absolute_redirect off;
    proxy_redirect ~^https?://${HOST_PATTERN}(?::\d+)?(/.*)?\$ /p/${TOKEN}\$1;
    proxy_redirect / /p/${TOKEN}/;
    proxy_cookie_path / /p/${TOKEN}/;
    proxy_cookie_domain ${UPSTREAM_HOST} \$host;

    sub_filter_once off;
    sub_filter_types text/css application/javascript text/javascript application/json;
    sub_filter "https://${UPSTREAM_HOST}/" "/p/${TOKEN}/";
    sub_filter "http://${UPSTREAM_HOST}/" "/p/${TOKEN}/";
    sub_filter '"https://${UPSTREAM_HOST}"' '"/p/${TOKEN}"';
    sub_filter '"http://${UPSTREAM_HOST}"' '"/p/${TOKEN}"';
    sub_filter 'href="//' 'href="//';
    sub_filter 'src="//' 'src="//';
    sub_filter 'href="/' 'href="/p/${TOKEN}/';
    sub_filter 'src="/' 'src="/p/${TOKEN}/';
    sub_filter 'action="/' 'action="/p/${TOKEN}/';
    sub_filter 'srcset="/' 'srcset="/p/${TOKEN}/';
    sub_filter 'url(/' 'url(/p/${TOKEN}/';
    sub_filter '"/build/' '"/p/${TOKEN}/build/';
    sub_filter '"/_next/' '"/p/${TOKEN}/_next/';
    sub_filter '"/_nuxt/' '"/p/${TOKEN}/_nuxt/';
    sub_filter '"/assets/' '"/p/${TOKEN}/assets/';
}
EOF
    if ! nginx -t 2>/dev/null; then
      if [[ -s "${PREVIEW_OLD}" ]]; then cp "${PREVIEW_OLD}" "${PREVIEW_FILE}"; else rm -f "${PREVIEW_FILE}"; fi
      rm -f "${PREVIEW_OLD}"
      # Never leave nginx unable to start: the shared return-trip files go too when they are the problem.
      if ! nginx -t 2>/dev/null; then
        rm -f /etc/nginx/conf.d/laravelify-preview-referer.conf "${PREVIEW_DIR}/00-preview-referer.conf"
      fi
      die "nginx rejected the preview location"
    fi
    rm -f "${PREVIEW_OLD}"
    systemctl reload nginx
    ;;
  code-server-install)
    # VS Code in the browser (code-server, MIT), one copy for the server in /opt/code-server.
    # Each website runs its own as its own user (code-server-start), reached through the panel.
    if [[ ! -x /opt/code-server/bin/code-server ]]; then
      echo "Installing code-server..."
      # The installer keeps its download in $XDG_CACHE_HOME (it resumes a broken download there);
      # the background service that runs this has no HOME of its own.
      export HOME=/root XDG_CACHE_HOME=/var/cache/laravelify
      install -d -m 0755 "${XDG_CACHE_HOME}"
      curl -fsSL https://code-server.dev/install.sh | sh -s -- --method=standalone --prefix=/opt/code-server >/dev/null
      [[ -x /opt/code-server/bin/code-server ]] || die "code-server could not be installed"
    fi
    # graphify (MIT): the project map the AI agents read first. Its own Python, via uv, in
    # /opt/graphify, readable by every website user. Code maps need no AI and no API key.
    if [[ ! -x /opt/graphify/bin/graphify ]]; then
      echo "Installing graphify..."
      export HOME=/root
      install -d -m 0755 /opt/graphify/bin /var/cache/laravelify
      if [[ ! -x /opt/graphify/bin/uv ]]; then
        curl -LsSf https://astral.sh/uv/install.sh | env UV_INSTALL_DIR=/opt/graphify/bin UV_NO_MODIFY_PATH=1 sh >/dev/null 2>&1 || true
      fi
      UV_TOOL_DIR=/opt/graphify/tools UV_TOOL_BIN_DIR=/opt/graphify/bin UV_PYTHON_INSTALL_DIR=/opt/graphify/python \
        UV_CACHE_DIR=/var/cache/laravelify/uv /opt/graphify/bin/uv tool install --quiet graphifyy >/dev/null 2>&1 \
        || echo "graphify could not be installed; the AI agents work without the project map." >&2
      chmod -R a+rX /opt/graphify 2>/dev/null || true
    fi
    install -d -m 0755 /etc/nginx/laravelify-panel.d
    # nginx asks the panel (with the visitor's cookies) before every request to an editor:
    # only a signed-in panel user gets through; anyone else is sent to the login page.
    cat > /etc/nginx/laravelify-panel.d/00-code-auth.conf <<'EOF'
location = /_laravelify/code-auth {
    internal;
    proxy_pass http://127.0.0.1/_access/code;
    proxy_pass_request_body off;
    proxy_set_header Content-Length "";
    proxy_set_header Host $host;
    proxy_set_header X-Original-URI $request_uri;
    proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;
}
location @laravelify_code_login {
    return 302 /login;
}
EOF
    nginx -t 2>/dev/null && systemctl reload nginx
    # Right after unpacking, node can still be "busy" (being written) for a moment.
    for _ in 1 2 3 4 5; do
      /opt/code-server/bin/code-server --version 2>/dev/null | head -1 | grep . && break
      sleep 1
    done
    true
    ;;
  code-server-install-start)
    # The download is large and can be slow: install in the background and answer at once.
    # Prints "installed", "installing <MB downloaded>", or "started".
    if [[ -x /opt/code-server/bin/code-server && -f /etc/nginx/laravelify-panel.d/00-code-auth.conf && -x /opt/graphify/bin/graphify ]]; then
      echo "installed"
    elif systemctl is-active --quiet laravelify-code-server-install || pgrep -f 'code-server-.*\.tar\.gz\.incomplete' >/dev/null; then
      size=$( (du -cm /var/cache/laravelify/code-server/*.incomplete /root/.cache/code-server/*.incomplete 2>/dev/null || true) | tail -1 | cut -f1)
      # The full size, from the release the installer is fetching (asked once, then remembered).
      total_file=/var/cache/laravelify/code-server.total
      file="$(ls /var/cache/laravelify/code-server/*.incomplete /root/.cache/code-server/*.incomplete 2>/dev/null | head -1 || true)"
      if [[ ! -s "${total_file}" && "${file}" =~ code-server-([0-9.]+)-linux-([a-z0-9]+)\.tar\.gz\.incomplete$ ]]; then
        install -d -m 0755 /var/cache/laravelify
        url="https://github.com/coder/code-server/releases/download/v${BASH_REMATCH[1]}/code-server-${BASH_REMATCH[1]}-linux-${BASH_REMATCH[2]}.tar.gz"
        bytes="$(curl -sIL --max-time 10 "${url}" | tr -d '\r' | awk 'tolower($1) == "content-length:" { n = $2 } END { print n + 0 }')"
        (( bytes > 0 )) && echo $(( bytes / 1048576 )) > "${total_file}"
      fi
      echo "installing ${size:-0} $(cat "${total_file}" 2>/dev/null || echo 0)"
    else
      systemctl reset-failed laravelify-code-server-install >/dev/null 2>&1 || true
      systemd-run --quiet --unit=laravelify-code-server-install --collect /bin/bash "${ROOT}/scripts/host-apply.sh" code-server-install all
      echo "started"
    fi
    ;;
  code-server-start)
    # code-server-start <slug> <port> <token> <workspace>: VS Code for one website, as its own
    # user, in the same sandbox as its terminal, on 127.0.0.1 only; nginx serves it at /code/<token>/.
    slug_ok "${TARGET}" || die "invalid slug"
    PORT="${3:-}"; TOKEN="${4:-}"; WORKSPACE="${5:-}"
    [[ "${PORT}" =~ ^[0-9]{4,5}$ ]] && (( PORT >= 1024 && PORT <= 65535 )) || die "invalid port"
    [[ "${TOKEN}" =~ ^[a-z0-9]{32}$ ]] || die "invalid token"
    [[ -x /opt/code-server/bin/code-server ]] || die "code-server is not installed"
    use_site "${TARGET}"
    site_has_own_user || die "VS Code needs the website to have its own user. Move it first (Security → own user)."
    [[ "${WORKSPACE}" == "${SITE_HOME}/workspaces/${TARGET}" ]] || die "invalid workspace"
    [[ -d "${WORKSPACE}" && ! -L "${WORKSPACE}" ]] || die "the workspace does not exist yet"
    UNIT="laravelify-code-${TARGET}"
    systemctl stop "${UNIT}" >/dev/null 2>&1 || true
    systemctl reset-failed "${UNIT}" >/dev/null 2>&1 || true
    # VS Code's limit covers what its terminals run too (npm run dev, next dev, the Live Server):
    # half the server's memory, at least 1.5 GB and at most 8 GB, so a dev server compiling a
    # big app no longer gets the editor killed ("Attempting to reconnect").
    CODE_MEMORY=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 2048 ))
    (( CODE_MEMORY < 1536 )) && CODE_MEMORY=1536
    (( CODE_MEMORY > 8192 )) && CODE_MEMORY=8192
    systemd-run --quiet --unit="${UNIT}" --collect \
      --uid="${SITE_USER}" --gid="$(id -gn "${SITE_USER}")" \
      --working-directory="${WORKSPACE}" \
      --property=UnsetEnvironment=yes \
      --setenv=PATH="${SITE_HOME}/.local/bin:/opt/graphify/bin:${PATH_SAFE}" \
      --setenv=HOME="${SITE_HOME}" \
      --setenv=LANG=C.UTF-8 \
      --setenv=SHELL=/bin/bash \
      --property=ProtectSystem=strict \
      --property=ProtectHome=yes \
      --property=PrivateTmp=yes \
      --property=ReadWritePaths="${SITES_ROOT}/${TARGET} ${SITE_HOME}" \
      --property=NoNewPrivileges=yes \
      --property=MemoryMax="${CODE_MEMORY}M" \
      /opt/code-server/bin/code-server \
        --bind-addr "127.0.0.1:${PORT}" --auth none \
        --disable-telemetry --disable-update-check --disable-workspace-trust \
        --abs-proxy-base-path "/code/${TOKEN}" \
        --app-name "Laravelify Code" \
        --idle-timeout-seconds 1800 \
        --user-data-dir "${SITE_HOME}/.code-server/data" \
        --extensions-dir "${SITE_HOME}/.code-server/extensions" \
        "${WORKSPACE}"
    write_code_editor_conf "/etc/nginx/laravelify-panel.d/code-${TARGET}.conf" "${TOKEN}" "${PORT}" "${UNIT}"
    echo "started ${UNIT}"
    ;;
  workspace-start)
    # workspace-start <key> <port> <token> <slug>...: one VS Code for several websites' projects,
    # as the workspace's own user (lfy-ws--<key>). It reaches exactly those projects' folders, through
    # ACLs (see grant_workspace_access); each website's home stays closed to it otherwise.
    workspace_key_ok "${TARGET}" || die "invalid workspace"
    PORT="${3:-}"; TOKEN="${4:-}"
    [[ "${PORT}" =~ ^[0-9]{4,5}$ ]] && (( PORT >= 1024 && PORT <= 65535 )) || die "invalid port"
    [[ "${TOKEN}" =~ ^[a-z0-9]{32}$ ]] || die "invalid token"
    [[ -x /opt/code-server/bin/code-server ]] || die "code-server is not installed"
    shift 4 2>/dev/null || true
    (( $# >= 1 )) || die "a workspace needs a project"
    ensure_workspace_user "${TARGET}"
    WS_USER="$(workspace_user_name "${TARGET}")"
    WS_HOME="${WORKSPACES_ROOT}/${TARGET}/home"
    FOLDERS=()
    for slug in "$@"; do
      slug_ok "${slug}" || die "invalid slug ${slug}"
      use_site "${slug}"
      site_has_own_user || die "${slug} still runs as the shared user: open its own VS Code once first"
      dir="${SITE_HOME}/workspaces/${slug}"
      [[ -d "${dir}" && ! -L "${dir}" ]] || die "the project ${slug} is not cloned yet"
      grant_workspace_access "${WS_USER}" "${SITE_USER}" "${SITE_HOME}" "${dir}"
      FOLDERS+=("${dir}")
    done
    # Its projects, so a deploy that resets a website's home gives the access back.
    printf '%s\n' "$@" > "${WORKSPACES_ROOT}/${TARGET}/sites"
    FILE="${WS_HOME}/workspace.code-workspace"
    {
      printf '{\n  "folders": [\n'
      for i in "${!FOLDERS[@]}"; do
        printf '    { "name": "%s", "path": "%s" }%s\n' "$(basename "${FOLDERS[$i]}")" "${FOLDERS[$i]}" "$([[ $i -lt $(( ${#FOLDERS[@]} - 1 )) ]] && echo ,)"
      done
      printf '  ]\n}\n'
    } > "${FILE}"
    chown "${WS_USER}:${WS_USER}" "${FILE}"
    # git refuses repositories another user owns unless told they are safe: these are the websites'.
    runuser -u "${WS_USER}" -- git config --file "${WS_HOME}/.gitconfig" --replace-all safe.directory '*'
    UNIT="laravelify-codews-${TARGET}"
    systemctl stop "${UNIT}" >/dev/null 2>&1 || true
    systemctl reset-failed "${UNIT}" >/dev/null 2>&1 || true
    CODE_MEMORY=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 2048 ))
    (( CODE_MEMORY < 1536 )) && CODE_MEMORY=1536
    (( CODE_MEMORY > 8192 )) && CODE_MEMORY=8192
    systemd-run --quiet --unit="${UNIT}" --collect \
      --uid="${WS_USER}" --gid="${WS_USER}" \
      --working-directory="${WS_HOME}" \
      --property=UnsetEnvironment=yes \
      --setenv=PATH="${WS_HOME}/.local/bin:/opt/graphify/bin:${PATH_SAFE}" \
      --setenv=HOME="${WS_HOME}" \
      --setenv=LANG=C.UTF-8 \
      --setenv=SHELL=/bin/bash \
      --property=ProtectSystem=strict \
      --property=ProtectHome=yes \
      --property=PrivateTmp=yes \
      --property=ReadWritePaths="${WS_HOME} ${FOLDERS[*]}" \
      --property=NoNewPrivileges=yes \
      --property=MemoryMax="${CODE_MEMORY}M" \
      /opt/code-server/bin/code-server \
        --bind-addr "127.0.0.1:${PORT}" --auth none \
        --disable-telemetry --disable-update-check --disable-workspace-trust \
        --abs-proxy-base-path "/code/${TOKEN}" \
        --app-name "Laravelify Code" \
        --idle-timeout-seconds 1800 \
        --user-data-dir "${WS_HOME}/.code-server/data" \
        --extensions-dir "${WS_HOME}/.code-server/extensions" \
        "${FILE}"
    write_code_editor_conf "/etc/nginx/laravelify-panel.d/code-ws-${TARGET}.conf" "${TOKEN}" "${PORT}" "${UNIT}"
    echo "started ${UNIT}"
    ;;
  glitchtip-install)
    # GlitchTip (MIT), error tracking for the websites: Sentry SDKs in Laravel, PHP, Node and
    # browser apps send their errors to it. Native, like the rest of the panel: its own user and
    # PostgreSQL database, Python packages through uv, one process (web + worker) under Supervisor
    # on 127.0.0.1:8095, reached at /glitchtip on the panel's address (browsers send errors there).
    # Running it again updates the settings, or GlitchTip itself when a newer version is given.
    # Prints "TOKEN=<api token>" and, the first time, "PASSWORD=<admin password>".
    url="${TARGET%/}"
    [[ "${url}" =~ ^https?://[A-Za-z0-9.:-]+$ ]] || die "invalid panel address"
    version="${3:-v6.2.6}"
    [[ "${version}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "invalid GlitchTip version"
    email="${4:-}"
    [[ "${email}" =~ ^[^[:space:]@]+@[^[:space:]@]+$ ]] || die "invalid email"
    gt_root=/opt/glitchtip
    gt_app="${gt_root}/${version}"
    export DEBIAN_FRONTEND=noninteractive HOME=/root
    echo "Installing PostgreSQL..." >&2
    command -v psql >/dev/null || apt-get install -y -q postgresql >/dev/null
    command -v unzip >/dev/null || apt-get install -y -q unzip >/dev/null
    systemctl enable --now postgresql >/dev/null 2>&1 || true
    id glitchtip >/dev/null 2>&1 || useradd --system --home-dir "${gt_root}" --shell /usr/sbin/nologin glitchtip
    install -d -o glitchtip -g glitchtip -m 0750 "${gt_root}" "${gt_root}/uploads"
    if [[ ! -f "${gt_root}/env" ]]; then
      pass="$(openssl rand -hex 16)"
      sudo -u postgres psql -qc "CREATE ROLE glitchtip LOGIN PASSWORD '${pass}'" >/dev/null 2>&1 || sudo -u postgres psql -qc "ALTER ROLE glitchtip PASSWORD '${pass}'" >/dev/null
      sudo -u postgres psql -qc "CREATE DATABASE glitchtip OWNER glitchtip" >/dev/null 2>&1 || true
      umask 027
      cat > "${gt_root}/env" <<ENV
DATABASE_URL=postgres://glitchtip:${pass}@127.0.0.1:5432/glitchtip
VALKEY_URL=
SECRET_KEY=$(openssl rand -hex 32)
EMAIL_URL=consolemail://
DEFAULT_FROM_EMAIL=${email}
ENABLE_USER_REGISTRATION=False
GLITCHTIP_ENABLE_UPTIME=False
BASE_PATH=/glitchtip
GRANIAN_HOST=127.0.0.1
GRANIAN_PORT=8095
GRANIAN_STATIC_PATH_ROUTE=/glitchtip/static
UPLOAD_ROOT=${gt_root}/uploads
ENV
      umask 022
      chown root:glitchtip "${gt_root}/env"
      if [[ "$(sudo -u postgres psql -tAc 'SHOW max_locks_per_transaction')" -lt 512 ]]; then
        sudo -u postgres psql -qc "ALTER SYSTEM SET max_locks_per_transaction = 512" >/dev/null
        systemctl restart postgresql
      fi
    fi
    # The panel's address can change: errors are sent to it, and the sign-in checks it.
    sed -i -e '/^GLITCHTIP_DOMAIN=/d' -e '/^CSRF_TRUSTED_ORIGINS=/d' "${gt_root}/env"
    printf 'GLITCHTIP_DOMAIN=%s/glitchtip\nCSRF_TRUSTED_ORIGINS=%s\n' "${url}" "${url}" >> "${gt_root}/env"
    if [[ ! -f "${gt_app}/.venv/bin/python" ]]; then
      echo "Downloading GlitchTip ${version}..." >&2
      tmp="$(mktemp -d)"
      curl -fsSL "https://gitlab.com/glitchtip/glitchtip-backend/-/archive/${version}/glitchtip-backend-${version}.tar.gz" | tar -xz -C "${tmp}"
      curl -fsSL "https://gitlab.com/api/v4/projects/15449363/jobs/artifacts/${version}/download?job=build-assets" -o "${tmp}/assets.zip"
      unzip -q "${tmp}/assets.zip" -d "${tmp}/assets"
      rm -rf "${gt_app}"
      mv "${tmp}/glitchtip-backend-${version}" "${gt_app}"
      rm -rf "${gt_app}/dist"
      mv "${tmp}/assets/dist/glitchtip-frontend/browser" "${gt_app}/dist"
      rm -rf "${tmp}"
      chown -R glitchtip:glitchtip "${gt_app}"
      if [[ ! -x "${gt_root}/bin/uv" ]]; then
        curl -LsSf https://astral.sh/uv/install.sh | env UV_INSTALL_DIR="${gt_root}/bin" UV_NO_MODIFY_PATH=1 sh >/dev/null 2>&1
      fi
      echo "Installing its Python packages..." >&2
      # uwsgi is one of two servers it can use and needs a compiler; it runs on granian here.
      (cd "${gt_app}" && sudo -u glitchtip env HOME="${gt_root}" UV_CACHE_DIR="${gt_root}/.cache/uv" UV_PYTHON_INSTALL_DIR="${gt_root}/python" \
        "${gt_root}/bin/uv" sync --frozen --no-dev --no-install-project --no-install-package uwsgi >/dev/null 2>&1) \
        || die "GlitchTip's Python packages could not be installed"
    fi
    gt_run() { sudo -u glitchtip bash -c "set -a; . ${gt_root}/env; set +a; cd ${gt_app}; . .venv/bin/activate; $1"; }
    echo "Preparing its database..." >&2
    gt_run "python manage.py migrate --no-input >/dev/null && python manage.py collectstatic --no-input >/dev/null" || die "GlitchTip's database could not be prepared"
    ln -sfn "${gt_app}" "${gt_root}/current"
    cat > /etc/supervisor/conf.d/laravelify-glitchtip.conf <<CONF
[program:laravelify-glitchtip]
command=/bin/bash -c 'set -a; . ${gt_root}/env; set +a; cd ${gt_root}/current; . .venv/bin/activate; exec ./bin/run-all-in-one.sh'
user=glitchtip
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/log/laravelify-glitchtip.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=2
CONF
    supervisorctl reread >/dev/null && supervisorctl update >/dev/null
    supervisorctl restart laravelify-glitchtip >/dev/null
    write_glitchtip_nginx
    for _ in $(seq 1 30); do
      curl -fs -o /dev/null http://127.0.0.1:8095/glitchtip/api/settings/ && break
      sleep 2
    done
    # The panel's admin in GlitchTip, and an API token with every scope (it makes a project per website).
    password="$(openssl rand -base64 18 | tr -d '/+=')"
    cat > "${gt_root}/bootstrap.py" <<'PY'
import os
from django.contrib.auth import get_user_model
from apps.api_tokens.models import APIToken
User = get_user_model()
user = User.objects.filter(email=os.environ["GT_EMAIL"]).first() or User.objects.filter(is_superuser=True).first()
if user is None:
    user = User.objects.create_superuser(email=os.environ["GT_EMAIL"], password=os.environ["GT_PASSWORD"])
    print("PASSWORD=" + os.environ["GT_PASSWORD"])
token = APIToken.objects.filter(user=user, label="Laravelify panel").first()
if token is None:
    token = APIToken.objects.create(user=user, label="Laravelify panel")
    for flag, _ in token.scopes.items():
        setattr(token.scopes, flag, True)
    token.save()
print("EMAIL=" + user.email)
print("TOKEN=" + token.token)
PY
    chown glitchtip:glitchtip "${gt_root}/bootstrap.py"
    gt_run "GT_EMAIL='${email}' GT_PASSWORD='${password}' python manage.py shell < ${gt_root}/bootstrap.py" 2>/dev/null | grep -E '^(PASSWORD|EMAIL|TOKEN)=' \
      || die "GlitchTip started, but its admin could not be made"
    ;;
  ai-chat-user)
    # The panel's AI chat runs the agents (Claude Code, Cursor Agent, Copilot CLI) as its own user,
    # lfy-ws--panelaichat: like a workspace's, it gets the shared AI sign-in, but no website's
    # files. It changes the server only through the panel's MCP tools, with its own token.
    ensure_workspace_user panelaichat
    install -d -o "$(workspace_user_name panelaichat)" -g "$(workspace_user_name panelaichat)" -m 0700 \
      "${WORKSPACES_ROOT}/panelaichat/home/chat" "${WORKSPACES_ROOT}/panelaichat/home/.laravelify"
    echo "ready"
    ;;
  workspace-stop)
    workspace_key_ok "${TARGET}" || die "invalid workspace"
    systemctl stop "laravelify-codews-${TARGET}" >/dev/null 2>&1 || true
    rm -f "/etc/nginx/laravelify-panel.d/code-ws-${TARGET}.conf"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    echo "stopped"
    ;;
  workspace-status)
    # "<active|inactive> <seconds since the editor was last used, or -1>"
    workspace_key_ok "${TARGET}" || die "invalid workspace"
    state="$(systemctl is-active "laravelify-codews-${TARGET}" 2>/dev/null || true)"
    beat="${WORKSPACES_ROOT}/${TARGET}/home/.local/share/code-server/heartbeat"
    age=-1
    [[ -f "${beat}" ]] && age=$(( $(date +%s) - $(stat -c %Y "${beat}") ))
    echo "${state:-inactive} ${age}"
    ;;
  workspace-drop)
    # workspace-drop <key> <slug>...: stop the editor, take its access to these projects back, and
    # remove its user and home. The projects themselves stay as they are.
    workspace_key_ok "${TARGET}" || die "invalid workspace"
    shift 2 2>/dev/null || true
    WS_USER="$(workspace_user_name "${TARGET}")"
    systemctl stop "laravelify-codews-${TARGET}" >/dev/null 2>&1 || true
    rm -f "/etc/nginx/laravelify-panel.d/code-ws-${TARGET}.conf"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    if id -u "${WS_USER}" >/dev/null 2>&1; then
      for slug in "$@"; do
        slug_ok "${slug}" || continue
        use_site "${slug}"
        site_has_own_user || continue
        revoke_workspace_access "${WS_USER}" "${SITE_HOME}" "${SITE_HOME}/workspaces/${slug}"
      done
      pkill -u "${WS_USER}" >/dev/null 2>&1 || true
      userdel "${WS_USER}" >/dev/null 2>&1 || true
    fi
    [[ -d "${WORKSPACES_ROOT}/${TARGET}" && ! -L "${WORKSPACES_ROOT}/${TARGET}" ]] && rm -rf -- "${WORKSPACES_ROOT:?}/${TARGET}"
    echo "dropped"
    ;;
  workspace-zip)
    # workspace-zip <name> <slug>...: the projects' code in one zip for the panel to send, without
    # installed packages, builds, and .env files (secrets). Links are stored as links, never followed.
    [[ "${TARGET}" =~ ^[a-z0-9-]{8,64}$ ]] || die "invalid name"
    shift 2 2>/dev/null || true
    (( $# >= 1 )) || die "nothing to zip"
    command -v zip >/dev/null 2>&1 || die "zip is not installed: sudo apt-get install -y zip"
    OUT_DIR="${ROOT}/storage/app/private/workspace-zips"
    install -d -o "${PANEL_USER}" -g "${PANEL_USER}" -m 0700 "${OUT_DIR}"
    OUT="${OUT_DIR}/${TARGET}.zip"
    rm -f -- "${OUT}"
    for slug in "$@"; do
      slug_ok "${slug}" || die "invalid slug ${slug}"
      use_site "${slug}"
      dir="${SITE_HOME}/workspaces/${slug}"
      [[ -d "${dir}" && ! -L "${dir}" ]] || continue
      (cd "${SITE_HOME}/workspaces" && zip -r -q -y "${OUT}" "${slug}" \
        -x "${slug}/node_modules/*" "*/node_modules/*" "${slug}/vendor/*" "${slug}/.next/*" "${slug}/.nuxt/*" \
           "${slug}/.svelte-kit/*" "*.env" "*/.env.*" "${slug}/.env.*" "${slug}/storage/logs/*") || true
    done
    [[ -f "${OUT}" ]] || die "the projects have no files to zip yet"
    chown "${PANEL_USER}:${PANEL_USER}" "${OUT}"
    chmod 0600 "${OUT}"
    echo "${OUT}"
    ;;
  agent-auth-sync)
    # One sign-in for Claude Code, Cursor Agent, and Copilot CLI across every website (the owner
    # chose this): each website's user has its own copy, since one user cannot read another's
    # files. Claude and Cursor replace their tokens as they refresh them, so the newest copy wins
    # and goes to every website. A sign-out (the files gone from a website that had them) signs
    # out everywhere instead of being copied back.
    AUTH_STATE="${STATE_DIR}/agent-auth"
    install -d -m 0700 "${AUTH_STATE}"
    # Websites by slug, VS Code workspaces (their homes too) as ws--<key>.
    home_label() {
      local base
      base="$(basename "$(dirname "$1")")"
      [[ "$1" == "${STATE_DIR}/workspaces/"* ]] && echo "ws--${base}" || echo "${base}"
    }
    sync_agent() {
      local name="$1" primary="$2"; shift 2
      local extra=("$@") home slug newest="" newest_time=0 had now_have=() gone=0 t
      for home in "${SITE_USERS_ROOT}"/*/home "${STATE_DIR}"/workspaces/*/home; do
        [[ -d "${home}" && ! -L "${home}" ]] || continue
        slug="$(home_label "${home}")"
        if [[ -f "${home}/${primary}" && ! -L "${home}/${primary}" ]]; then
          now_have+=("${slug}")
          t="$(stat -c %Y "${home}/${primary}")"
          (( t > newest_time )) && newest_time="${t}" && newest="${home}"
        elif [[ -f "${AUTH_STATE}/${name}.sites" ]] && grep -qx "${slug}" "${AUTH_STATE}/${name}.sites"; then
          gone=1
        fi
      done
      if (( gone )); then
        for home in "${SITE_USERS_ROOT}"/*/home "${STATE_DIR}"/workspaces/*/home; do
          rm -f -- "${home}/${primary}" 2>/dev/null || true
          for t in "${extra[@]}"; do rm -f -- "${home}/${t}" 2>/dev/null || true; done
        done
        rm -f -- "${AUTH_STATE}/${name}.sites"
        echo "${name}: signed out on every website"
        return
      fi
      [[ -n "${newest}" ]] || return 0
      local copied=0 owner group sum
      sum="$(sha256sum "${newest}/${primary}" | cut -d' ' -f1)"
      for home in "${SITE_USERS_ROOT}"/*/home "${STATE_DIR}"/workspaces/*/home; do
        [[ -d "${home}" && ! -L "${home}" && "${home}" != "${newest}" ]] || continue
        [[ -f "${home}/${primary}" ]] && [[ "$(sha256sum "${home}/${primary}" | cut -d' ' -f1)" == "${sum}" ]] && continue
        owner="$(stat -c %U "${home}")"; group="$(stat -c %G "${home}")"
        [[ "${owner}" == lfy-* ]] || continue
        for t in "${primary}" "${extra[@]}"; do
          [[ -f "${newest}/${t}" && ! -L "${newest}/${t}" ]] || continue
          [[ -L "${home}/$(dirname "${t}")" ]] && continue
          # Never the home itself (.claude.json sits in it): setting its mode would reset the ACL
          # mask that lets VS Code workspaces pass through to the project.
          # Each folder on the way is the site's (install -d gives only the last one): a root-owned
          # .config stopped build tools that keep their settings there (Astro, npm tools).
          if [[ "$(dirname "${t}")" != "." ]]; then
            dir="${home}"
            IFS=/ read -ra parts <<< "$(dirname "${t}")"
            for part in "${parts[@]}"; do
              dir="${dir}/${part}"
              [[ -L "${dir}" ]] && break
              if [[ -d "${dir}" ]]; then
                [[ "$(stat -c %U "${dir}")" == root ]] && chown "${owner}:${group}" "${dir}"
              else
                install -d -o "${owner}" -g "${group}" -m 0700 "${dir}"
              fi
            done
          fi
          install -o "${owner}" -g "${group}" -m 0600 "${newest}/${t}" "${home}/${t}"
        done
        copied=$(( copied + 1 ))
      done
      # The websites that have it now: one of them losing it later is a sign-out.
      : > "${AUTH_STATE}/${name}.sites"
      for home in "${SITE_USERS_ROOT}"/*/home "${STATE_DIR}"/workspaces/*/home; do
        [[ -f "${home}/${primary}" ]] && home_label "${home}" >> "${AUTH_STATE}/${name}.sites"
      done
      (( copied )) && echo "${name}: sign-in copied to ${copied} website(s)"
      return 0
    }
    sync_agent claude .claude/.credentials.json .claude.json
    sync_agent cursor .config/cursor/auth.json .cursor/cli-config.json
    sync_agent copilot .copilot/config.json
    # Folders an earlier sync left root's (it made .config as root) go back to the site.
    for home in "${SITE_USERS_ROOT}"/*/home "${STATE_DIR}"/workspaces/*/home; do
      [[ -d "${home}" && ! -L "${home}" ]] || continue
      owner="$(stat -c %U "${home}")"
      [[ "${owner}" == lfy-* ]] || continue
      for dir in .config .claude .cursor .copilot; do
        [[ -d "${home}/${dir}" && ! -L "${home}/${dir}" && "$(stat -c %U "${home}/${dir}")" == root ]] \
          && chown "${owner}:$(stat -c %G "${home}")" "${home}/${dir}"
      done
    done
    # VS Code workspaces pass through their projects' homes by an ACL that any chmod of a home
    # resets: give it back every minute, whatever reset it.
    for list in "${WORKSPACES_ROOT}"/*/sites; do
      [[ -f "${list}" ]] || continue
      while IFS= read -r slug; do
        slug_ok "${slug}" || continue
        restore_workspace_access "${slug}" "${SITE_USERS_ROOT}/${slug}/home"
      done < "${list}"
    done
    ;;
  panel-worker-start)
    # The panel's own queue worker and scheduler, when Supervisor gave up on them (FATAL after
    # MySQL was down for a moment). Nothing else can be started this way.
    supervisorctl start laravelify-queue 'laravelify-deploys:*' laravelify-scheduler 2>&1 || true
    supervisorctl status laravelify-queue 'laravelify-deploys:*' laravelify-scheduler 2>&1 || true
    ;;
  code-server-stop)
    slug_ok "${TARGET}" || die "invalid slug"
    systemctl stop "laravelify-code-${TARGET}" >/dev/null 2>&1 || true
    rm -f "/etc/nginx/laravelify-panel.d/code-${TARGET}.conf"
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    echo "stopped"
    ;;
  code-server-status)
    # "<active|inactive> <seconds since VS Code was last used, or -1>"
    slug_ok "${TARGET}" || die "invalid slug"
    use_site "${TARGET}"
    state="$(systemctl is-active "laravelify-code-${TARGET}" 2>/dev/null || true)"
    beat="${SITE_HOME}/.local/share/code-server/heartbeat"
    age=-1
    [[ -f "${beat}" ]] && age=$(( $(date +%s) - $(stat -c %Y "${beat}") ))
    echo "${state:-inactive} ${age}"
    ;;
  panel-preview-remove)
    slug_ok "${TARGET}" || die "invalid slug"
    rm -f "/etc/nginx/laravelify-panel.d/preview-${TARGET}.conf"
    nginx -t && systemctl reload nginx
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
    validate_nginx "${RENDERED}/services/phpmyadmin.conf" panel
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
  glitchtip-remove)
    # Turn off error tracking: GlitchTip's process, nginx locations, database (with every error
    # in it), files, and user go. PostgreSQL itself stays: other things may use it.
    supervisorctl stop laravelify-glitchtip >/dev/null 2>&1 || true
    rm -f /etc/supervisor/conf.d/laravelify-glitchtip.conf
    supervisorctl reread >/dev/null 2>&1 || true
    supervisorctl update >/dev/null 2>&1 || true
    rm -f /etc/nginx/laravelify-panel.d/50-glitchtip.conf
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    if command -v psql >/dev/null && id postgres >/dev/null 2>&1; then
      (cd /tmp && sudo -u postgres psql -qc "DROP DATABASE IF EXISTS glitchtip WITH (FORCE)" >/dev/null 2>&1) \
        || (cd /tmp && sudo -u postgres psql -qc "DROP DATABASE IF EXISTS glitchtip" >/dev/null) || die "GlitchTip's database could not be deleted"
      (cd /tmp && sudo -u postgres psql -qc "DROP ROLE IF EXISTS glitchtip" >/dev/null) || true
    fi
    pkill -u glitchtip >/dev/null 2>&1 || true
    rm -rf -- /opt/glitchtip
    rm -f /var/log/laravelify-glitchtip.log*
    id glitchtip >/dev/null 2>&1 && userdel glitchtip >/dev/null 2>&1 || true
    echo "GlitchTip is removed."
    ;;
  glitchtip-nginx)
    # Run by every update: servers with GlitchTip get its current nginx locations.
    [[ -f /etc/supervisor/conf.d/laravelify-glitchtip.conf ]] || { echo "GlitchTip is not installed."; exit 0; }
    write_glitchtip_nginx
    echo "GlitchTip opens through the panel's sign-in."
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
    create_site_folder "${TARGET}"
    if [[ "$(id -u)" -eq 0 ]]; then
      own_site_folder "/var/www/${TARGET}"
    fi
    ;;
  site-user)
    # Move a site to its own user (lfy-<slug>): its folder, and the items of the shared trash
    # that came from it (their ids follow the slug). The panel then writes the site again, which
    # moves its PHP to its own pool, its workers to its user, and its PM2 app to its own daemon.
    slug_ok "${TARGET}" || die "invalid slug"
    site_users_enabled || die "websites cannot have their own users yet: run scripts/privileges.sh first"
    SITE_DIR="/var/www/${TARGET}"
    [[ -d "${SITE_DIR}" && ! -L "${SITE_DIR}" ]] || die "${SITE_DIR} is not a folder"
    ensure_site_user "${TARGET}"
    own="$(own_user_name "${TARGET}")"
    if [[ "$(stat -c '%U' -- "${SITE_DIR}")" != "${own}" ]]; then
      echo "Giving ${SITE_DIR} to ${own}"
      chown -P -h "${own}:${READ_GROUP}" -- "${SITE_DIR}"
    fi
    use_site "${TARGET}"
    site_has_own_user || die "could not give ${SITE_DIR} to ${own}"
    own_site_folder "${SITE_DIR}"
    for id in "${@:3}"; do
      [[ "${id}" =~ ^[0-9]+$ ]] || continue
      item="${STATE_DIR}/trash/${id}"
      [[ -e "${item}" && ! -L "${item}" ]] || continue
      [[ -e "${SITE_USERS_ROOT}/${TARGET}/trash/${id}" ]] && continue
      mv -T -- "${item}" "${SITE_USERS_ROOT}/${TARGET}/trash/${id}"
      chown -R -P -h "${own}:${READ_GROUP}" -- "${SITE_USERS_ROOT}/${TARGET}/trash/${id}"
    done
    echo "${TARGET} now runs as ${own}."
    ;;
  site-user-drop)
    # Give a site back to the shared user (scripts/rollback-site-users.sh, or a removed site).
    slug_ok "${TARGET}" || die "invalid slug"
    drop_site_user "${TARGET}"
    echo "${TARGET} runs as ${SITE_USER}."
    ;;
  restore-folder)
    # Where folder backups are restored to when not over the originals: written by the shared
    # user, readable by the panel, never by sites with their own users.
    dir="${SITES_ROOT}/laravelify-restore"
    if [[ ! -e "${dir}" ]]; then
      if site_users_enabled; then
        install -d -o "${SHARED_USER}" -g "${READ_GROUP}" -m 2750 "${dir}"
      else
        install -d -o "${SHARED_USER}" -g "${SHARED_USER}" -m 0755 "${dir}"
      fi
    fi
    [[ -d "${dir}" && ! -L "${dir}" ]] || die "${dir} is not a folder"
    ;;
  run)
    [[ -n "${3:-}" ]] || die "missing command"
    root="$(realpath -e -- "${TARGET}")" || die "missing site folder"
    case "${root}" in
      /var/www/*) ;;
      *) die "path is outside a site folder" ;;
    esac
    [[ "${root}" != "/var/www" && -d "${root}" ]] || die "path is outside a site folder"
    slug="${root#/var/www/}"
    slug="${slug%%/*}"
    slug_ok "${slug}" || die "path is outside a site folder"
    use_site "${slug}"
    # A site's own user has its own home; the shared user keeps the site folder as HOME.
    home="${root}"
    writable="${root}"
    if site_has_own_user; then
      home="${SITE_HOME}"
      writable="${root} ${SITE_HOME}"
    fi
    exec systemd-run --quiet ${IO_MODE} --wait --collect \
      --uid="${SITE_USER}" --gid="$(id -gn "${SITE_USER}")" \
      --working-directory="${root}" \
      --property=UnsetEnvironment=yes \
      --setenv=PATH="${PATH_SAFE}" \
      --setenv=HOME="${home}" \
      --setenv=LANG=C.UTF-8 \
      --property=ProtectSystem=strict \
      --property=ProtectHome=yes \
      --property=PrivateTmp=yes \
      --property=ReadWritePaths="${writable}" \
      --property=NoNewPrivileges=yes \
      --property=RuntimeMaxSec=${MAX_SECONDS} \
      /bin/bash -lc "$3"
    ;;
  run-global)
    # The panel's global terminal: same sandbox as "run", in any folder under /var/www
    # (including /var/www itself). Once sites have their own users, a site folder is the same
    # as "run" for that site, and /var/www itself is nobody's, read-only: a user that could read
    # every site would let any site's code read all the others.
    [[ -n "${3:-}" ]] || die "missing command"
    root="$(realpath -e -- "${TARGET}")" || die "missing folder"
    case "${root}" in
      /var/www|/var/www/*) ;;
      *) die "path is outside /var/www" ;;
    esac
    [[ -d "${root}" ]] || die "path is not a folder"
    if site_users_enabled; then
      if [[ "${root}" == /var/www ]]; then
        exec systemd-run --quiet ${IO_MODE} --wait --collect \
          --uid=nobody --gid=nogroup \
          --working-directory="${root}" \
          --property=UnsetEnvironment=yes \
          --setenv=PATH="${PATH_SAFE}" \
          --setenv=HOME=/var/www \
          --setenv=LANG=C.UTF-8 \
          --property=ProtectSystem=strict \
          --property=ProtectHome=yes \
          --property=PrivateTmp=yes \
          --property=NoNewPrivileges=yes \
          --property=RuntimeMaxSec=${MAX_SECONDS} \
          /bin/bash -lc "$3"
      fi
      slug="${root#/var/www/}"
      slug="${slug%%/*}"
      slug_ok "${slug}" || die "open a website's folder to run commands in it"
      use_site "${slug}"
      home="${root}"
      writable="/var/www/${slug}"
      if site_has_own_user; then
        home="${SITE_HOME}"
        writable="/var/www/${slug} ${SITE_HOME}"
      fi
      exec systemd-run --quiet ${IO_MODE} --wait --collect \
        --uid="${SITE_USER}" --gid="$(id -gn "${SITE_USER}")" \
        --working-directory="${root}" \
        --property=UnsetEnvironment=yes \
        --setenv=PATH="${PATH_SAFE}" \
        --setenv=HOME="${home}" \
        --setenv=LANG=C.UTF-8 \
        --property=ProtectSystem=strict \
        --property=ProtectHome=yes \
        --property=PrivateTmp=yes \
        --property=ReadWritePaths="${writable}" \
        --property=NoNewPrivileges=yes \
        --property=RuntimeMaxSec=${MAX_SECONDS} \
        /bin/bash -lc "$3"
    fi
    exec systemd-run --quiet ${IO_MODE} --wait --collect \
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
      --property=RuntimeMaxSec=${MAX_SECONDS} \
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
    # Site PM2 apps run in their site user's PM2 daemon (an app is named after its site).
    # Usage: pm2 jlist all | pm2 restart|delete|logs <name>
    command -v pm2 >/dev/null 2>&1 || { echo "[]"; exit 0; }
    case "${TARGET}" in
      jlist)
        pm2_jlist_all
        exit 0
        ;;
      restart|delete|logs) ;;
      *) die "unknown pm2 command" ;;
    esac
    [[ "${3:-}" =~ ^[A-Za-z0-9_.:-]+$ ]] || die "invalid process name"
    if slug_ok "$3"; then
      use_site "$3"
    fi
    case "${TARGET}" in
      restart) site_pm2 restart "$3" --update-env && site_pm2 save --force >/dev/null 2>&1 ;;
      delete) site_pm2 delete "$3" && site_pm2 save --force >/dev/null 2>&1 ;;
      logs) site_pm2 logs "$3" --nostream --lines 400 --raw ;;
    esac
    ;;
  pm2-boot)
    # Run by update.sh: every Node site that has a build runs in its site user's PM2 daemon
    # (moved out of root's daemon of older versions), the lists are saved, and pm2-<user>
    # brings them back after a reboot.
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
      use_site "${name}"
      chown "root:${SITE_USER}" "${config}" && chmod 640 "${config}"
      pm2_leave_root "${name}"
      if ! site_pm2 describe "${name}" >/dev/null 2>&1; then
        echo "Starting ${name} in PM2"
        site_pm2 startOrReload "${config}" --update-env >/dev/null 2>&1 || echo "warning: could not start ${name}" >&2
      fi
      if site_has_own_user; then
        pm2_persist
      fi
    done
    SITE_USER="${SHARED_USER}"
    SITE_GROUP="${SHARED_USER}"
    SITE_HOME="${SHARED_HOME}"
    if pm2_running "${SHARED_HOME}/.pm2"; then
      pm2_persist
    fi
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
    use_site "${TARGET}"
    check_site_nginx "${SRC}"
    install_site_nginx "${TARGET}" "${SRC}"
    remove_site_pool "${TARGET}"
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
    systemctl stop "laravelify-code-${TARGET}" >/dev/null 2>&1 || true
    rm -f "/etc/nginx/laravelify-panel.d/code-${TARGET}.conf"
    # The site's own user goes; its folder, owned by the shared user again, moves to the trash in
    # /var/www/.trash as <slug>-<date>, so it can still be brought back by moving it.
    drop_site_user "${TARGET}"
    remove_site_pool "${TARGET}"
    if [[ -d "${SITES_ROOT}/${TARGET}" && ! -L "${SITES_ROOT}/${TARGET}" ]]; then
      install -d -m 2750 -o "${SITE_USER}" -g "${READ_GROUP}" "${SITES_ROOT}/.trash"
      trashed="${SITES_ROOT}/.trash/${TARGET}-$(date +%Y-%m-%d-%H%M%S)"
      mv -T -- "${SITES_ROOT}/${TARGET}" "${trashed}"
      echo "Folder moved to ${trashed}"
    fi
    sync_site_firewall "/etc/nginx/sites-available/laravelify-${TARGET}.conf" /dev/null
    rm -f "/etc/nginx/sites-enabled/laravelify-${TARGET}.conf" \
      "/etc/nginx/sites-available/laravelify-${TARGET}.conf" \
      "/etc/nginx/laravelify-security/${TARGET}.conf" \
      "/etc/nginx/laravelify-security/${TARGET}.htpasswd" \
      "/etc/nginx/conf.d/laravelify-security-${TARGET}.conf" \
      "/etc/nginx/laravelify-panel.d/preview-${TARGET}.conf" \
      "/etc/supervisor/conf.d/laravelify-${TARGET}.conf" \
      "/var/log/laravelify/${TARGET}.metrics.log" \
      "${PM2_APPS}/${TARGET}.config.cjs" \
      "${PM2_APPS}/${TARGET}.start"
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
