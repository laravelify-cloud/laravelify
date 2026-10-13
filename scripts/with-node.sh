#!/usr/bin/env bash
# Run a command with a Node.js major version installed by `n`, when that version exists.
set -euo pipefail

version="${1:?node major version}"
shift

if [[ -d /usr/local/n/versions/node ]]; then
  match="$(find /usr/local/n/versions/node -mindepth 1 -maxdepth 1 -type d -name "${version}.*" | sort -V | tail -n 1 || true)"
  if [[ -n "${match}" && -d "${match}/bin" ]]; then
    export PATH="${match}/bin:${PATH}"
  fi
fi

exec "$@"
