#!/usr/bin/env bash
# Run a command with a website's toolchain on PATH: Python (its .venv, through uv), Go, Bun or
# Hugo, installed under /opt/laravelify-tools by host-apply.sh toolchain-install. Then hands over
# to with-node.sh, which loads the site's .env and puts Node.js on PATH too (builds often need it).
#   with-toolchain.sh <python|go|bun|hugo> <version> <command> [args...]
set -euo pipefail

toolchain="${1:?toolchain}"
version="${2:?version}"
shift 2
TOOLS=/opt/laravelify-tools

# The newest installed folder of a version: 3.12 matches 3.12.7, 1.24 matches 1.24.3.
newest() {
  local base="$1" wanted="$2"
  if [[ -d "${base}/${wanted}" ]]; then
    echo "${base}/${wanted}"
  else
    find "${base}" -mindepth 1 -maxdepth 1 -type d -name "${wanted}.*" 2>/dev/null | sort -V | tail -n 1
  fi
}

export LARAVELIFY_RUNTIME_VERSION="${version}"

# Caches and tool state live under HOME (pip, Go, Maven, NuGet, .NET's locks). A HOME the site user
# cannot write to (root's, or none) breaks them, so it falls back to one inside the site folder.
if [[ -z "${HOME:-}" || ! -w "${HOME}" ]]; then
  export HOME="${PWD}/.laravelify-home"
  mkdir -p "${HOME}"
fi

case "${toolchain}" in
  python)
    export PATH="${TOOLS}/uv:${PATH}"
    export UV_PYTHON_INSTALL_DIR="${TOOLS}/python"
    export UV_PYTHON_PREFERENCE=only-managed
    export UV_CACHE_DIR="${HOME:-/tmp}/.cache/uv"
    export PYTHONUNBUFFERED=1
    if [[ -d "${PWD}/.venv/bin" ]]; then
      export VIRTUAL_ENV="${PWD}/.venv"
      export PATH="${VIRTUAL_ENV}/bin:${PATH}"
    fi
    # Django: the WSGI module, from the first */wsgi.py next to manage.py (mysite/wsgi.py → mysite.wsgi).
    if [[ -z "${LARAVELIFY_WSGI:-}" && -f "${PWD}/manage.py" ]]; then
      wsgi="$(find "${PWD}" -mindepth 2 -maxdepth 2 -name wsgi.py -not -path '*/.venv/*' 2>/dev/null | sort | head -n 1)"
      if [[ -n "${wsgi}" ]]; then
        wsgi="${wsgi#"${PWD}/"}"
        export LARAVELIFY_WSGI="${wsgi%/wsgi.py}.wsgi"
      fi
    fi
    ;;
  go)
    dir="$(newest "${TOOLS}/go" "${version}")"
    [[ -n "${dir}" ]] && export PATH="${dir}/bin:${PATH}"
    export GOPATH="${HOME:-/tmp}/go"
    export GOCACHE="${HOME:-/tmp}/.cache/go-build"
    export GOFLAGS="${GOFLAGS:--mod=mod}"
    ;;
  bun)
    dir="$(newest "${TOOLS}/bun" "${version}")"
    [[ -n "${dir}" ]] && export PATH="${dir}/bin:${PATH}"
    export BUN_INSTALL_CACHE_DIR="${HOME:-/tmp}/.bun/install/cache"
    ;;
  java)
    dir="$(newest "${TOOLS}/java" "${version}")"
    if [[ -n "${dir}" ]]; then
      export JAVA_HOME="${dir}"
      export PATH="${dir}/bin:${PATH}"
    fi
    [[ -d "${TOOLS}/maven/bin" ]] && export PATH="${TOOLS}/maven/bin:${PATH}"
    export MAVEN_OPTS="${MAVEN_OPTS:--Dmaven.repo.local=${HOME:-/tmp}/.m2/repository}"
    export GRADLE_USER_HOME="${HOME:-/tmp}/.gradle"
    ;;
  dotnet)
    dir="$(newest "${TOOLS}/dotnet" "${version}")"
    if [[ -n "${dir}" ]]; then
      export DOTNET_ROOT="${dir}"
      export PATH="${dir}:${PATH}"
    fi
    export DOTNET_CLI_TELEMETRY_OPTOUT=1
    export DOTNET_NOLOGO=1
    export DOTNET_CLI_HOME="${HOME:-/tmp}"
    export NUGET_PACKAGES="${HOME:-/tmp}/.nuget/packages"
    # .NET keeps its named locks under $TMPDIR/.dotnet: a shared /tmp/.dotnet belongs to whoever
    # ran .NET first, and every other site user then fails ("NuGet-Migrations").
    export TMPDIR="${HOME:-/tmp}/.cache/dotnet-tmp"
    mkdir -p "${TMPDIR}"
    [[ -n "${PORT:-}" ]] && export ASPNETCORE_URLS="${ASPNETCORE_URLS:-http://127.0.0.1:${PORT}}"
    ;;
  hugo)
    dir="$(newest "${TOOLS}/hugo" "${version}")"
    [[ -n "${dir}" ]] && export PATH="${dir}:${PATH}"
    ;;
  *)
    echo "unknown toolchain ${toolchain}" >&2
    exit 64
    ;;
esac

exec bash "$(dirname "$0")/with-node.sh" "${LARAVELIFY_NODE_VERSION:-22}" "$@"
