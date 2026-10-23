#!/usr/bin/env bash
# Update /opt/laravelify without touching the live .env, database, storage,
# or vendor tree. Dev-only caches are left behind so packages such as
# Laravel Boost are not booted in production.
#
#   sudo bash /opt/laravelify/scripts/update.sh             latest GitHub release
#   sudo LARAVELIFY_VERSION=1.2.0 bash .../update.sh         a specific release
#   sudo LARAVELIFY_SOURCE=/path/to/laravelify bash ...      a local copy (development)
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  exec sudo bash "$0" "$@"
fi

APP_ROOT="${LARAVELIFY_ROOT:-/opt/laravelify}"
# The panel's own pool is the PHP Composer and artisan must use. Defaulting to
# php8.4 fails when the install only provisioned 8.3, and a bare `composer`
# follows whatever `php` was already on the server.
if [[ -z "${LARAVELIFY_PHP:-}" ]]; then
  for conf in /etc/php/*/fpm/pool.d/laravelify.conf; do
    if [[ -f "$conf" ]]; then
      version="${conf#/etc/php/}"
      LARAVELIFY_PHP="php${version%%/*}"
      break
    fi
  done
fi
PHP_BIN="${LARAVELIFY_PHP:-php8.4}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  for version in 8.4 8.3 8.5; do
    if command -v "php${version}" >/dev/null 2>&1; then
      PHP_BIN="php${version}"
      break
    fi
  done
fi
LARAVELIFY_REPO="${LARAVELIFY_REPO:-laravelify-cloud/laravelify}"
LARAVELIFY_VERSION="${LARAVELIFY_VERSION:-latest}"
DEV_SOURCE="/mnt/c/Users/ADMIN/Herd/new-ff-apply-apps/laravelify"

if [[ -n "${LARAVELIFY_SOURCE:-}" ]]; then
  SOURCE="$LARAVELIFY_SOURCE"
elif [[ -f "${DEV_SOURCE}/artisan" ]]; then
  # The author's WSL checkout; everyone else gets releases.
  SOURCE="$DEV_SOURCE"
else
  if [[ "$LARAVELIFY_VERSION" == "latest" ]]; then
    ARCHIVE_URL="https://github.com/${LARAVELIFY_REPO}/releases/latest/download/laravelify.tar.gz"
  else
    ARCHIVE_URL="https://github.com/${LARAVELIFY_REPO}/releases/download/v${LARAVELIFY_VERSION#v}/laravelify.tar.gz"
  fi

  SOURCE="$(mktemp -d /tmp/laravelify-update.XXXXXX)"
  LARAVELIFY_RELEASE=1
  trap 'rm -rf "$SOURCE"' EXIT
  echo "Downloading ${ARCHIVE_URL}"
  curl -fsSL "$ARCHIVE_URL" | tar -xz -C "$SOURCE" --strip-components=1

  if [[ -f "${APP_ROOT}/VERSION" && -f "${SOURCE}/VERSION" ]]; then
    echo "Version $(cat "${APP_ROOT}/VERSION") -> $(cat "${SOURCE}/VERSION")"
  fi
fi

if [[ ! -f "${SOURCE}/artisan" ]]; then
  echo "Panel source not found at ${SOURCE}" >&2
  exit 1
fi

if [[ ! -f "${APP_ROOT}/artisan" ]]; then
  echo "Installed panel not found at ${APP_ROOT}" >&2
  exit 1
fi

MANIFEST="${SOURCE}/public/build/manifest.json"

if [[ ! -f "${MANIFEST}" ]]; then
  echo "No built assets at ${MANIFEST}. Run 'npm run build' in the panel source first." >&2
  exit 1
fi

# Tailwind scans the Blade views, so any newer view, CSS, or JS file means the build is stale.
# A release is built by the release workflow and unpacked with its own file times, so only a
# source checkout is compared.
STALE=""
if [[ -z "${LARAVELIFY_RELEASE:-}" ]]; then
  STALE="$(find "${SOURCE}/resources" -type f -newer "${MANIFEST}" -print -quit)"
fi

if [[ -n "${STALE}" ]]; then
  echo "Built assets are older than ${STALE#"${SOURCE}"/}." >&2
  echo "Run 'npm run build' in the panel source, then run this update again." >&2
  exit 1
fi

echo "Updating ${APP_ROOT} from ${SOURCE}"

tar -C "${SOURCE}" \
  --exclude=vendor \
  --exclude=node_modules \
  --exclude=storage \
  --exclude=bootstrap/cache \
  --exclude=.env \
  --exclude=.git \
  -cf - . | tar -C "${APP_ROOT}" -xf - --no-overwrite-dir

sed -i 's/\r$//' "${APP_ROOT}"/scripts/*.sh
chmod 755 "${APP_ROOT}"/scripts/*.sh

cd "${APP_ROOT}"
export COMPOSER_ALLOW_SUPERUSER=1
"$(command -v "$PHP_BIN")" "$(command -v composer)" install --no-dev --optimize-autoloader --no-interaction --no-ansi
"${PHP_BIN}" artisan view:clear --no-ansi
"${PHP_BIN}" artisan config:clear --no-ansi
"${PHP_BIN}" artisan route:clear --no-ansi
"${PHP_BIN}" artisan migrate --force --no-ansi
"${PHP_BIN}" artisan laravelify:mysql-defaults --no-ansi || true
"${PHP_BIN}" artisan laravelify:upload-limits --no-ansi || true
bash "${APP_ROOT}/scripts/host-apply.sh" phpmyadmin-hide all || true

# The panel runs as its own user (laravelify), websites as www-data. The first update with this
# moves an older install over (users, PHP-FPM pool, nginx, sudo, Supervisor, PM2); later ones
# put owners and permissions back after the copy above. See scripts/privileges.sh.
LARAVELIFY_ROOT="${APP_ROOT}" LARAVELIFY_PHP="${PHP_BIN}" bash "${APP_ROOT}/scripts/privileges.sh"
systemctl reload "php${PHP_BIN#php}-fpm" || true

# Deploys run inside the queue worker, a long-running process that keeps the old
# code in memory. queue:restart lets it finish the current job (a deploy in
# progress is not killed) and exit; Supervisor starts it again on the new code.
runuser -u laravelify -- "${PHP_BIN}" "${APP_ROOT}/artisan" queue:restart --no-ansi || true
supervisorctl start laravelify-queue >/dev/null 2>&1 || true
# schedule:work also keeps the old code in memory.
supervisorctl restart laravelify-scheduler >/dev/null 2>&1 || true

# Backups to S3 use rclone. Older installs do not have it yet; add it once, quietly.
if ! command -v rclone >/dev/null 2>&1; then
  echo "Installing rclone for backups..."
  apt-get install -y rclone >/dev/null 2>&1 || echo "Could not install rclone. Backups need it: sudo apt-get install -y rclone" >&2
fi

# Websites resize images with GD (JPEG, PNG, WebP). New installs have it; older ones do not.
# Add it for every PHP version installed, then reload that version's PHP-FPM. Workers and
# queue processes started before this keep running without it until they restart.
while read -r package; do
  version="${package#php}"
  version="${version%-fpm}"
  if ! dpkg -s "php${version}-gd" 2>/dev/null | grep -q '^Status: install ok installed'; then
    echo "Installing php${version}-gd for image resizing..."
    if apt-get install -y "php${version}-gd" >/dev/null 2>&1; then
      systemctl reload "php${version}-fpm" >/dev/null 2>&1 || true
    else
      echo "Could not install php${version}-gd: sudo apt-get install -y php${version}-gd" >&2
    fi
  fi
done < <(dpkg-query -W -f='${Package}\n' 'php[0-9].[0-9]-fpm' 2>/dev/null)

# Website previews at <panel>/p/<token>/ are nginx locations in /etc/nginx/laravelify-panel.d,
# included by the panel's server block. Older installs get the include once.
PANEL_NGINX=/etc/nginx/sites-available/laravelify-panel.conf
install -d -m 0755 /etc/nginx/laravelify-panel.d
if [[ -f "${PANEL_NGINX}" ]] && ! grep -q 'laravelify-panel.d' "${PANEL_NGINX}"; then
  cp "${PANEL_NGINX}" "${PANEL_NGINX}.bak"
  sed -i '0,/client_max_body_size 256M;/s//client_max_body_size 256M;\n\n    # Website previews at \/p\/<token>\/ (scripts\/host-apply.sh panel-preview).\n    include \/etc\/nginx\/laravelify-panel.d\/*.conf;/' "${PANEL_NGINX}"
  if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx || true
  else
    mv "${PANEL_NGINX}.bak" "${PANEL_NGINX}"
    echo "Could not add website previews to the panel's nginx config." >&2
  fi
  rm -f "${PANEL_NGINX}.bak"
fi

# Previews are rebuilt with the current rules (redirect and link rewriting).
"${PHP_BIN}" "${APP_ROOT}/artisan" laravelify:refresh-previews --no-ansi || true

# VS Code for the websites (code-server) and the AI agents' project map (graphify), downloaded
# here once instead of the first time someone opens the editor. Nothing happens when installed.
if [[ ! -x /opt/code-server/bin/code-server || ! -x /opt/graphify/bin/graphify ]]; then
  echo "Installing VS Code (code-server) for the websites..."
fi
bash "${APP_ROOT}/scripts/host-apply.sh" code-server-install >/dev/null \
  || echo "VS Code could not be installed now; it installs the first time someone opens the editor." >&2

echo "Panel updated."
