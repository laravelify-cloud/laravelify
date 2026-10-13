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
PHP_BIN="${LARAVELIFY_PHP:-php8.4}"
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
  -cf - . | tar -C "${APP_ROOT}" -xf -

sed -i 's/\r$//' "${APP_ROOT}"/scripts/*.sh
chmod 755 "${APP_ROOT}/scripts/host-apply.sh" "${APP_ROOT}/scripts/with-node.sh" "${APP_ROOT}/scripts/update.sh"

cd "${APP_ROOT}"
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --no-ansi
"${PHP_BIN}" artisan view:clear --no-ansi
"${PHP_BIN}" artisan config:clear --no-ansi
"${PHP_BIN}" artisan route:clear --no-ansi
"${PHP_BIN}" artisan migrate --force --no-ansi
systemctl reload php8.4-fpm

# Deploys run inside the queue worker, a long-running process that keeps the old
# code in memory. queue:restart lets it finish the current job (a deploy in
# progress is not killed) and exit; Supervisor starts it again on the new code.
"${PHP_BIN}" artisan queue:restart --no-ansi
supervisorctl start laravelify-queue >/dev/null 2>&1 || true

# The scheduler runs the monitor behind the Telegram alerts (sites, workers,
# disk, patches). Older installs did not have it.
if ! grep -qs '^\[program:laravelify-scheduler\]' /etc/supervisor/conf.d/*.conf; then
  cat > /etc/supervisor/conf.d/laravelify-scheduler.conf <<EOF
[program:laravelify-scheduler]
command=${PHP_BIN} ${APP_ROOT}/artisan schedule:work
directory=${APP_ROOT}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
environment=HOME="${APP_ROOT}/storage/app/process-home"
redirect_stderr=true
stdout_logfile=${APP_ROOT}/storage/logs/panel-scheduler.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
EOF
  supervisorctl reread >/dev/null || true
  supervisorctl update || true
else
  # schedule:work also keeps the old code in memory.
  supervisorctl restart laravelify-scheduler >/dev/null 2>&1 || true
fi

# Next.js sites: one PM2 daemon under /root, saved, and restored on boot by pm2-root.
bash "${APP_ROOT}/scripts/host-apply.sh" pm2-boot all || echo "PM2 boot setup failed; run: sudo bash ${APP_ROOT}/scripts/host-apply.sh pm2-boot all" >&2

echo "Panel updated."
