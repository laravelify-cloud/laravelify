#!/usr/bin/env bash
# Laravelify installer bootstrap, served at https://cdn.laravelify.cloud/install.sh
#
#   curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash
#
# This file does not change between releases, so CDN caching can never serve an
# old installer. It downloads the installer of the latest GitHub release (or the
# one in LARAVELIFY_VERSION) and runs it. Every variable the installer reads
# (MYSQL_ROOT_PASSWORD, PANEL_DOMAIN, ...) is passed through.

# Everything is inside main() so bash reads the whole file before running it,
# even when it arrives through a pipe.
main() {
  set -euo pipefail

  local repo="${LARAVELIFY_REPO:-laravelify-cloud/laravelify}"
  local version="${LARAVELIFY_VERSION:-latest}"
  local url

  if [[ "$version" == "latest" ]]; then
    url="https://github.com/${repo}/releases/latest/download/install.sh"
  else
    url="https://github.com/${repo}/releases/download/v${version#v}/install.sh"
  fi

  if [[ "$(id -u)" -ne 0 ]]; then
    echo "Run this as root: curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash" >&2
    exit 1
  fi

  local installer
  installer="$(mktemp /tmp/laravelify-install.XXXXXX.sh)"
  # Expanded now: the local variable is gone by the time the EXIT trap runs.
  trap "rm -f '${installer}'" EXIT

  echo "Downloading the Laravelify installer (${version}) from GitHub"
  if ! curl -fsSL --retry 3 "$url" -o "$installer"; then
    echo "Could not download ${url}" >&2
    exit 1
  fi

  export LARAVELIFY_REPO="$repo" LARAVELIFY_VERSION="$version"

  # The installer asks its questions on /dev/tty, so stdin is not needed.
  bash "$installer" "$@" </dev/null
}

main "$@"
