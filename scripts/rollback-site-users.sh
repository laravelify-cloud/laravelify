#!/usr/bin/env bash
# Give websites back to the shared www-data user: undo the per-site users of scripts/privileges.sh.
#
#   sudo bash /opt/laravelify/scripts/rollback-site-users.sh                 # every website
#   sudo bash /opt/laravelify/scripts/rollback-site-users.sh --site=<slug>   # one website
#
# For each website: its files and trash go back to www-data, its PHP runs in the shared pool of its
# PHP version again, its workers run as www-data, its PM2 app moves to www-data's daemon, and its
# own user (lfy-<slug>) is deleted. Its folder, database, and settings stay.
#
# Without --site, new websites also stop getting their own user: /var/lib/laravelify/.no-site-users
# is written and /var/www belongs to www-data again. Delete that file and run privileges.sh to
# turn per-site users on again.
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  exec sudo bash "$0" "$@"
fi

APP_ROOT="${LARAVELIFY_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STATE_DIR=/var/lib/laravelify
PANEL_USER=laravelify
SITE_USER=www-data
PATH_SAFE=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

say() {
  echo "[rollback-site-users] $*"
}

fail() {
  echo "[rollback-site-users] error: $*" >&2
  exit 1
}

SITES=()
for argument in "$@"; do
  case "${argument}" in
    --site=*) SITES+=("--site=${argument#--site=}") ;;
    *) fail "unknown option ${argument} (use --site=<slug>)" ;;
  esac
done

id -u "${PANEL_USER}" >/dev/null 2>&1 || { say "The panel has no user of its own: nothing to roll back."; exit 0; }

PHP_BIN="$(command -v php)"
for file in /etc/php/*/fpm/pool.d/laravelify.conf; do
  if [[ -f "${file}" ]]; then
    version="${file#/etc/php/}"
    PHP_BIN="/usr/bin/php${version%%/*}"
  fi
done

as_panel() {
  runuser -u "${PANEL_USER}" -- env -i PATH="${PATH_SAFE}" HOME="${APP_ROOT}/storage/app/process-home" LANG=C.UTF-8 "$@"
}

if [[ ${#SITES[@]} -eq 0 ]]; then
  say "New websites keep www-data from now on (${STATE_DIR}/.no-site-users)"
  touch "${STATE_DIR}/.no-site-users"
  SITES=(--all)
fi

status=0
as_panel "${PHP_BIN}" "${APP_ROOT}/artisan" laravelify:separate-sites --undo "${SITES[@]}" --no-ansi || status=$?

if [[ "${SITES[0]}" == --all ]]; then
  install -d -o "${SITE_USER}" -g "${SITE_USER}" -m 0755 /var/www
  left=""
  for dir in /var/www/*/; do
    owner="$(stat -c '%U' -- "${dir}")"
    if [[ "${owner}" == lfy-* ]]; then
      left="${left}${dir} (${owner})"$'\n'
    fi
  done
  if [[ -n "${left//[[:space:]]/}" ]]; then
    say "warning: these folders still belong to a site's own user (see above):"
    echo "${left}"
    status=1
  fi
fi

if [[ "${status}" -eq 0 ]]; then
  say "Done."
else
  say "Some websites were not given back (see above). Fix the cause and run this again."
fi
exit "${status}"
