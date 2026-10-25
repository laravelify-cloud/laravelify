#!/usr/bin/env bash
# Run a command with a Node.js major version installed by `n`, when that version exists.
# With LARAVELIFY_ENV_FILE set (PM2 apps), the site's .env is loaded first: PM2 itself does not
# read it, and built servers (Nuxt, Next.js standalone, SvelteKit) do not either.
set -euo pipefail

version="${1:?node major version}"
shift

if [[ -d /usr/local/n/versions/node ]]; then
  match="$(find /usr/local/n/versions/node -mindepth 1 -maxdepth 1 -type d -name "${version}.*" | sort -V | tail -n 1 || true)"
  if [[ -n "${match}" && -d "${match}/bin" ]]; then
    export PATH="${match}/bin:${PATH}"
  fi
fi

# KEY=value lines, as the panel writes them: plain, 'single quoted' (literal), or "double quoted"
# (\" and \\ escaped). Nothing is evaluated. Values PM2 already set (PORT, HOST) win.
if [[ -n "${LARAVELIFY_ENV_FILE:-}" && -r "${LARAVELIFY_ENV_FILE}" ]]; then
  while IFS= read -r line || [[ -n "${line}" ]]; do
    line="${line%$'\r'}"
    [[ "${line}" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)[[:space:]]*=(.*)$ ]] || continue
    key="${BASH_REMATCH[2]}"
    value="${BASH_REMATCH[3]}"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"

    if [[ ${#value} -ge 2 && "${value:0:1}" == "'" && "${value: -1}" == "'" ]]; then
      value="${value:1:${#value}-2}"
    elif [[ ${#value} -ge 2 && "${value:0:1}" == '"' && "${value: -1}" == '"' ]]; then
      value="${value:1:${#value}-2}"
      value="${value//\\\"/\"}"
      value="${value//\\\\/\\}"
    else
      value="${value%%[[:space:]]#*}"
    fi

    [[ -v "${key}" ]] || export "${key}=${value}"
  done < "${LARAVELIFY_ENV_FILE}"
fi
unset LARAVELIFY_ENV_FILE

exec "$@"
