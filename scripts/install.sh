#!/usr/bin/env bash
# Install Laravelify on Ubuntu, the same way Coolify is installed:
#   curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash
#
# Downloads the latest release from GitHub (LARAVELIFY_REPO) unless a local
# copy is given with LARAVELIFY_SOURCE.
#
# Asks for a Cloudflare tunnel token (checked first) and the panel domain; the database
# password is made for you (40 random characters).
# Installs MySQL, Redis, phpMyAdmin, Node.js, PM2, PHP-FPM, nginx,
# cloudflared, and a firewall that allows only SSH, 80, and 443.
#
# Optional environment variables:
#   MYSQL_ROOT_PASSWORD       the database password (default: a random 40-character one)
#   PANEL_DOMAIN              skip the panel domain prompt
#   PHPMYADMIN_DOMAIN         phpMyAdmin on a domain of its own (old style)
#   PHPMYADMIN_PATH           phpMyAdmin at this path of the panel's address (default /phpmyadmin)
#   LARAVELIFY_PACK           full (default) or minimal: no phpMyAdmin, no VS Code
#   LARAVELIFY_TOOLS          exactly which tools: phpmyadmin,vscode,glitchtip (default: phpmyadmin,vscode), or --tools=
#   CLOUDFLARE_TUNNEL_TOKEN   skip the tunnel token prompt (asked first and checked)
#   LARAVELIFY_CLOUD_KEY      an install key from app.laravelify.cloud: a free <name>.laravelify.cloud address, no Cloudflare questions
#   CLOUDFLARE_API_TOKEN      skip the optional API token prompt (empty: none); it adds the panel route and DNS
#   LARAVELIFY_VERSION        release to install, like 1.2.0 (default: latest)
#   LARAVELIFY_REPO           GitHub owner/name to download releases from
#   LARAVELIFY_SOURCE         directory to copy into /opt/laravelify instead
#   LARAVELIFY_ARCHIVE_URL    tar.gz of the panel to download instead
#   LARAVELIFY_PHP_VERSIONS   PHP-FPM versions to install, like "8.3 8.4" (default: 8.4; the panel adds others when a website needs them)
#   LARAVELIFY_NODE_VERSIONS  Node.js majors to install, like "22 24" (default: 24; the panel adds others when a website needs them)
#   LARAVELIFY_DATABASES      database servers to install, like "mysql postgresql" (default: mysql)
#
# The same choices as options (the install command builder on laravelify.cloud writes these):
#   curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash -s -- --php=8.3,8.4 --node=22 --databases=postgresql
#   --php=LIST  --node=LIST  --databases=LIST  --tools=LIST  --panel-domain=HOST  --phpmyadmin-domain=HOST  --help

set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Laravelify installer

  curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash
  curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash -s -- [options]

Options:
  --php=8.3,8.4               PHP-FPM versions to install (7.4, 8.0 to 8.5; default: 8.4).
                              Websites can pick another later: the panel installs it.
  --node=22,24                Node.js majors to install (18, 20, 22, 24; default: 24)
  --databases=mysql,postgresql
                              database servers for the websites: mysql or mariadb (one of
                              the two), postgresql, mongodb, or none (default: mysql).
                              SQLite needs no server and always works.
  --panel-domain=HOST         the panel's domain, like panel.example.com
  --tools=phpmyadmin,vscode   optional tools: phpmyadmin, vscode, glitchtip, or none
                              (default: phpmyadmin,vscode)
  --phpmyadmin-path=PATH      phpMyAdmin at this path of the panel's address (default /phpmyadmin)
  --phpmyadmin-domain=HOST    or on a domain of its own instead
  -h, --help                  show this help

The Cloudflare tunnel token is always asked on the server,
so it never ends up in a shell history or a copied command.
USAGE
}

for argument in "$@"; do
  case "$argument" in
    --php=*) LARAVELIFY_PHP_VERSIONS="${argument#*=}" ;;
    --node=*) LARAVELIFY_NODE_VERSIONS="${argument#*=}" ;;
    --databases=*) LARAVELIFY_DATABASES="${argument#*=}" ;;
    --panel-domain=*) PANEL_DOMAIN="${argument#*=}" ;;
    --phpmyadmin-domain=*) PHPMYADMIN_DOMAIN="${argument#*=}" ;;
    --phpmyadmin-path=*) PHPMYADMIN_PATH="${argument#*=}" ;;
    --tools=*) LARAVELIFY_TOOLS="${argument#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: ${argument}" >&2; usage >&2; exit 1 ;;
  esac
done

# The release workflow replaces this with the real repository when it uploads the script.
LARAVELIFY_REPO="${LARAVELIFY_REPO:-laravelify-cloud/laravelify}"
LARAVELIFY_VERSION="${LARAVELIFY_VERSION:-latest}"

if [[ "$LARAVELIFY_VERSION" == "latest" ]]; then
  DEFAULT_ARCHIVE_URL="https://github.com/${LARAVELIFY_REPO}/releases/latest/download/laravelify.tar.gz"
else
  DEFAULT_ARCHIVE_URL="https://github.com/${LARAVELIFY_REPO}/releases/download/v${LARAVELIFY_VERSION#v}/laravelify.tar.gz"
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script as root: curl -fsSL https://cdn.laravelify.cloud/install.sh | sudo bash" >&2
  exit 1
fi

export DEBIAN_FRONTEND=noninteractive

# --- Console ------------------------------------------------------------------------------------
# Each step shows one line: a spinner while it runs, then a check mark and how long it took.
# What the commands print goes to the log; when a step fails, its last lines are shown.
LOG_FILE=/var/log/laravelify-install.log
( umask 077; : > "$LOG_FILE" ) 2>/dev/null || LOG_FILE="$(mktemp /tmp/laravelify-install.XXXXXX.log)"
chmod 600 "$LOG_FILE" 2>/dev/null || true
exec 3>&1 4>&2

FANCY=0
if [[ -t 3 && -z "${NO_COLOR:-}" && "${TERM:-dumb}" != dumb ]]; then
  FANCY=1
fi

if [[ "$FANCY" == 1 ]]; then
  C_RESET=$'\033[0m' C_BOLD=$'\033[1m' C_DIM=$'\033[2m' C_RED=$'\033[31m' C_GREEN=$'\033[32m'
  C_YELLOW=$'\033[33m' C_CYAN=$'\033[36m' C_MAGENTA=$'\033[35m'
else
  C_RESET='' C_BOLD='' C_DIM='' C_RED='' C_GREEN='' C_YELLOW='' C_CYAN='' C_MAGENTA=''
fi

STEP_LABEL=""
STEP_START=0
SPINNER_PID=""
INSTALL_START=$SECONDS

duration() {
  local seconds="$1"
  if (( seconds >= 60 )); then
    printf '%dm %02ds' $((seconds / 60)) $((seconds % 60))
  else
    printf '%ds' "$seconds"
  fi
}

ui() { printf '%s\n' "$*" >&3; }
ui_bar() { printf '%s│%s\n' "$C_DIM" "$C_RESET" >&3; }

# A line under the current step (a skipped version, a fallback), also kept in the log.
note() {
  if [[ -n "$SPINNER_PID" ]]; then
    printf '\r\033[K' >&3
  fi
  printf '%s│  %s%s\n' "$C_DIM" "$*" "$C_RESET" >&3
  printf '%s\n' "$*" >>"$LOG_FILE"
}

spinner_stop() {
  if [[ -n "$SPINNER_PID" ]]; then
    kill "$SPINNER_PID" 2>/dev/null || true
    wait "$SPINNER_PID" 2>/dev/null || true
    SPINNER_PID=""
    printf '\r\033[K' >&3
  fi
}

step_done() {
  [[ -n "$STEP_LABEL" ]] || return 0
  spinner_stop
  printf '%s◆%s  %s %s%s%s\n' "$C_GREEN" "$C_RESET" "$STEP_LABEL" "$C_DIM" "$(duration $((SECONDS - STEP_START)))" "$C_RESET" >&3
  STEP_LABEL=""
}

# Start a step: the previous one is done, and output goes to the log from here on.
step() {
  step_done
  STEP_LABEL="$1"
  STEP_START=$SECONDS
  printf '\n==> %s\n' "$1" >>"$LOG_FILE"
  exec >>"$LOG_FILE" 2>&1

  if [[ "$FANCY" == 1 ]]; then
    (
      trap - EXIT
      frames=('◒' '◐' '◓' '◑')
      i=0
      while :; do
        printf '\r\033[K%s%s%s  %s %s%s%s' "$C_CYAN" "${frames[i % 4]}" "$C_RESET" "$1" "$C_DIM" "$(duration $((SECONDS - STEP_START)))" "$C_RESET" >&3
        i=$((i + 1))
        sleep 0.12
      done
    ) &
    SPINNER_PID=$!
  else
    printf -- '- %s ...\n' "$1" >&3
  fi
}

on_exit() {
  local code=$?
  spinner_stop
  exec 1>&3 2>&4
  if (( code != 0 )); then
    printf '%s■%s  %s%s%s\n' "$C_RED" "$C_RESET" "$C_BOLD" "${STEP_LABEL:-Install} failed" "$C_RESET" >&2
    if [[ -s "$LOG_FILE" ]]; then
      printf '%s│%s\n' "$C_DIM" "$C_RESET" >&2
      tail -n 25 "$LOG_FILE" | sed "s/^/${C_DIM}│${C_RESET}  /" >&2
    fi
    printf '%s└%s  Full log: %s\n' "$C_DIM" "$C_RESET" "$LOG_FILE" >&2
  fi
  rm -rf /tmp/laravelify-install
}
trap on_exit EXIT

# The versions to install. Websites may use 7.4 to 8.5. The panel's Composer
# lock needs PHP 8.4.1 or newer (Symfony 8.1), so 8.4 is added unless 8.4 or
# 8.5 was already chosen.
PHP_VERSIONS="${LARAVELIFY_PHP_VERSIONS:-8.4}"
PHP_VERSIONS="$(tr ',' ' ' <<<"$PHP_VERSIONS" | xargs -n1 | sort -uV | xargs)"
for version in $PHP_VERSIONS; do
  if [[ ! "$version" =~ ^(7\.4|8\.[0-5])$ ]]; then
    echo "PHP ${version} is not one the installer offers (7.4, 8.0 to 8.5)." >&2
    exit 1
  fi
done
if [[ ! " $PHP_VERSIONS " =~ \ 8\.[45]\  ]]; then
  PHP_VERSIONS="$(xargs -n1 <<<"$PHP_VERSIONS 8.4" | sort -uV | xargs)"
  PHP_ADDED_NOTE="PHP 8.4 added: the panel itself needs PHP 8.4 or newer."
fi

NODE_VERSIONS="${LARAVELIFY_NODE_VERSIONS:-24}"
NODE_VERSIONS="$(tr ',' ' ' <<<"$NODE_VERSIONS" | xargs -n1 | sort -un | xargs)"
for version in $NODE_VERSIONS; do
  if [[ ! "$version" =~ ^(18|20|22|24)$ ]]; then
    echo "Node.js ${version} is not one the installer offers (18, 20, 22, 24)." >&2
    exit 1
  fi
done
# The system Node.js (for npm, PM2, and sites without a version of their own): the newest chosen.
NODE_BASE="${NODE_VERSIONS##* }"

# The database servers for the websites. MySQL and MariaDB are the same server (port 3306, the
# same package names), so at most one of them. SQLite is a file, not a server: always there.
# Without MySQL or MariaDB the panel keeps its own data in SQLite and phpMyAdmin is left out.
DATABASES="${LARAVELIFY_DATABASES:-mysql}"
DATABASES="$(tr ',' ' ' <<<"$DATABASES" | tr '[:upper:]' '[:lower:]' | xargs -n1 | sed -e 's/^postgres$/postgresql/' -e 's/^mongo$/mongodb/' | grep -vx 'sqlite' | sort -u | xargs || true)"
if [[ " $DATABASES " == *" none "* ]]; then
  DATABASES=""
fi
for database in $DATABASES; do
  if [[ ! "$database" =~ ^(mysql|mariadb|postgresql|mongodb)$ ]]; then
    echo "${database} is not a database the installer offers (mysql, mariadb, postgresql, mongodb, none)." >&2
    exit 1
  fi
done
if [[ " $DATABASES " == *" mysql "* && " $DATABASES " == *" mariadb "* ]]; then
  echo "Pick MySQL or MariaDB, not both: they are the same kind of server on the same port." >&2
  exit 1
fi
has_database() { [[ " $DATABASES " == *" $1 "* ]]; }
# MySQL or MariaDB: the panel's own data, phpMyAdmin, and the websites' MySQL databases.
MYSQL_FAMILY=""
has_database mysql && MYSQL_FAMILY="mysql"
has_database mariadb && MYSQL_FAMILY="mariadb"

# Optional tools. LARAVELIFY_PACK sets their defaults: full (default) has phpMyAdmin (with MySQL or
# MariaDB) and VS Code, minimal has none. LARAVELIFY_TOOLS (like "phpmyadmin,vscode,glitchtip") picks them exactly.
# GlitchTip needs the panel's admin, so the panel installs it right after the setup link is used.
LARAVELIFY_PACK="${LARAVELIFY_PACK:-full}"
if [[ -z "${LARAVELIFY_TOOLS+x}" ]]; then
  LARAVELIFY_TOOLS="$([[ "$LARAVELIFY_PACK" == "minimal" ]] && echo "" || echo "phpmyadmin,vscode")"
fi
has_tool() { [[ ",${LARAVELIFY_TOOLS// /,}," == *",$1,"* ]]; }
WITH_PHPMYADMIN=""
if [[ -n "$MYSQL_FAMILY" ]] && has_tool phpmyadmin; then
  WITH_PHPMYADMIN=1
fi
WITH_GLITCHTIP=""
has_tool glitchtip && WITH_GLITCHTIP=1

printf '\n%s┌%s  %sLaravelify%s %sinstaller · self-hosted deployment panel%s\n' "$C_DIM" "$C_RESET" "$C_BOLD$C_MAGENTA" "$C_RESET" "$C_DIM" "$C_RESET" >&3
ui_bar

read_tty() {
  if [[ -r /dev/tty ]]; then
    read -r "$@" </dev/tty
  else
    return 1
  fi
}

# Already installed? Update Laravelify only: the new release, migrations, and a restart of
# its workers. PHP, MySQL, nginx, Node, and the other packages are not touched.
# LARAVELIFY_REINSTALL=1 skips this and runs the full install (a repair).
INSTALLED_ROOT="${LARAVELIFY_ROOT:-/opt/laravelify}"

if [[ "${LARAVELIFY_REINSTALL:-}" != "1" && -z "${LARAVELIFY_SOURCE:-}" \
  && -f "${INSTALLED_ROOT}/artisan" && -f "${INSTALLED_ROOT}/.env" && -f "${INSTALLED_ROOT}/vendor/autoload.php" ]]; then
  UPDATE_DIR="$(mktemp -d /tmp/laravelify-update.XXXXXX)"
  UPDATE_ARCHIVE_URL="${LARAVELIFY_ARCHIVE_URL:-$DEFAULT_ARCHIVE_URL}"
  INSTALLED_VERSION="$(tr -d '\r\n' < "${INSTALLED_ROOT}/VERSION" 2>/dev/null || true)"
  echo "Laravelify ${INSTALLED_VERSION:-(unknown version)} is already installed. Checking the release..."

  if ! curl -fsSL "$UPDATE_ARCHIVE_URL" | tar -xz -C "$UPDATE_DIR" --strip-components=1; then
    rm -rf "$UPDATE_DIR"
    echo "Could not download ${UPDATE_ARCHIVE_URL}." >&2
    echo "Check LARAVELIFY_REPO / LARAVELIFY_VERSION, or that the GitHub release has laravelify.tar.gz attached." >&2
    exit 1
  fi

  echo "  Installed: ${INSTALLED_VERSION:-unknown}"
  echo "  Release:   $(tr -d '\r\n' < "${UPDATE_DIR}/VERSION" 2>/dev/null || echo unknown)"
  UPDATE_ANSWER=""
  # No terminal (automated run): update only.
  read_tty -p "Update Laravelify only? Packages and settings stay as they are. [Y/n] (n = reinstall everything) " UPDATE_ANSWER || UPDATE_ANSWER="y"
  echo

  if [[ ! "$UPDATE_ANSWER" =~ ^[Nn] ]]; then
    RELEASE_VERSION="$(tr -d '\r\n' < "${UPDATE_DIR}/VERSION" 2>/dev/null || echo unknown)"
    step "Updating Laravelify ${INSTALLED_VERSION:-} → ${RELEASE_VERSION}"
    LARAVELIFY_SOURCE="$UPDATE_DIR" LARAVELIFY_ROOT="$INSTALLED_ROOT" LARAVELIFY_RELEASE=1 bash "${UPDATE_DIR}/scripts/update.sh"
    step_done
    rm -rf "$UPDATE_DIR"
    exec 1>&3 2>&4
    printf '%s└%s  %sLaravelify %s is ready%s %s(%s)%s\n\n' "$C_DIM" "$C_RESET" "$C_GREEN$C_BOLD" "$RELEASE_VERSION" "$C_RESET" "$C_DIM" "$(duration $((SECONDS - INSTALL_START)))" "$C_RESET"
    exit 0
  fi

  rm -rf "$UPDATE_DIR"
fi

# Re-running the installer (to repair or finish an install) offers the answers
# from last time. They are saved root-only in /etc/laravelify/install.env, or,
# for older installs, read back from the existing installation.
STATE_FILE=/etc/laravelify/install.env

env_value() {
  grep -m1 "^$1=" /opt/laravelify/.env 2>/dev/null | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true
}

previous_settings() {
  if [[ -f "$STATE_FILE" ]]; then
    # shellcheck disable=SC1090
    . "$STATE_FILE"
  elif [[ -f /opt/laravelify/.env ]]; then
    PREV_PANEL_DOMAIN="$(env_value APP_URL | sed -e 's#^https\?://##' -e 's#/.*$##')"
    if [[ "$(env_value DB_USERNAME)" == "root" ]]; then
      PREV_MYSQL_ROOT_PASSWORD="$(env_value DB_PASSWORD)"
    fi
    PREV_PHPMYADMIN_DOMAIN="$(grep -m1 -oP 'server_name\s+\K[^;]+' /etc/nginx/sites-available/laravelify-phpmyadmin.conf 2>/dev/null || true)"
    PREV_CLOUDFLARE_TUNNEL_TOKEN="$(grep -h -oP -- '--token "\K[^"]+' /etc/systemd/system/laravelify-tunnel-*.service 2>/dev/null | head -n 1 || true)"
  fi

  [[ -n "${PREV_PANEL_DOMAIN:-}" ]]
}

masked() {
  if [[ -n "$1" ]]; then echo "******** (saved)"; else echo "(not found, you will be asked)"; fi
}

# Only what this install asks for: no password without a database server, no phpMyAdmin domain without MySQL or MariaDB.
if [[ ( -n "$DATABASES" && -z "${MYSQL_ROOT_PASSWORD:-}" ) || -z "${PANEL_DOMAIN:-}" || ( -n "$WITH_PHPMYADMIN" && -z "${PHPMYADMIN_DOMAIN:-}" && -z "${PHPMYADMIN_PATH:-}" && -n "${PREV_PHPMYADMIN_DOMAIN:-}" ) || -z "${CLOUDFLARE_TUNNEL_TOKEN:-}" ]] && previous_settings; then
  echo
  echo "Found the settings from a previous install:"
  echo "  Panel domain:            ${PREV_PANEL_DOMAIN}"
  if [[ -n "${PREV_PHPMYADMIN_DOMAIN:-}" ]]; then
    echo "  phpMyAdmin domain:       ${PREV_PHPMYADMIN_DOMAIN}"
  fi
  echo "  Database password:       $(masked "${PREV_MYSQL_ROOT_PASSWORD:-}")"
  echo "  Cloudflare tunnel token: $(masked "${PREV_CLOUDFLARE_TUNNEL_TOKEN:-}")"
  REUSE_ANSWER=""
  # No terminal (automated install): reuse them.
  read_tty -p "Use these settings? [Y/n] " REUSE_ANSWER || REUSE_ANSWER="y"
  echo
  if [[ ! "$REUSE_ANSWER" =~ ^[Nn] ]]; then
    MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-${PREV_MYSQL_ROOT_PASSWORD:-}}"
    PANEL_DOMAIN="${PANEL_DOMAIN:-${PREV_PANEL_DOMAIN:-}}"
    PHPMYADMIN_DOMAIN="${PHPMYADMIN_DOMAIN:-${PREV_PHPMYADMIN_DOMAIN:-}}"
    CLOUDFLARE_TUNNEL_TOKEN="${CLOUDFLARE_TUNNEL_TOKEN:-${PREV_CLOUDFLARE_TUNNEL_TOKEN:-}}"
    CLOUDFLARE_API_TOKEN="${CLOUDFLARE_API_TOKEN-}"
  fi
fi

# One password for the database servers chosen: MySQL's or MariaDB's root, PostgreSQL's postgres
# user, and MongoDB's admin. Not asked when there is no database server (SQLite only).
if [[ -n "$DATABASES" ]]; then
  # Not asked: a random 40-character password is made. The panel shows it after your authenticator
  # code (Server → MySQL), and it can be changed there. A repair reuses the previous one.
  if [[ -z "${MYSQL_ROOT_PASSWORD:-}" ]]; then
    MYSQL_ROOT_PASSWORD="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 40 || true)"
  fi

  if [[ -z "$MYSQL_ROOT_PASSWORD" || "$MYSQL_ROOT_PASSWORD" =~ [[:space:]\'\"\\] ]]; then
    echo "The database password is required and cannot contain spaces, quotes, or backslashes." >&2
    exit 1
  fi
else
  MYSQL_ROOT_PASSWORD=""
fi

# The panel's address comes first: a free https://<name>.laravelify.cloud one (Laravelify Cloud makes
# its tunnel and DNS, with the account's install key from app.laravelify.cloud → Dashboard), or the
# user's own domain on their own Cloudflare tunnel. The server keeps a secret of its own in
# /etc/laravelify/cloud-server-id, so installing again gives the same free address.
CLOUD_URL="${LARAVELIFY_CLOUD_URL:-https://app.laravelify.cloud}"
FREE_ADDRESS=""
CLOUD_TOKEN=""
if [[ -z "${CLOUDFLARE_TUNNEL_TOKEN:-}" ]]; then
  if [[ -n "${LARAVELIFY_CLOUD_KEY:-}" ]]; then
    FREE_ADDRESS=1
  else
    printf '%s│%s  Panel address:\n' "$C_DIM" "$C_RESET" >&3
    printf '%s│%s    1) a free https://<name>.laravelify.cloud address (needs your install key from app.laravelify.cloud)\n' "$C_DIM" "$C_RESET" >&3
    printf '%s│%s    2) your own domain (needs a Cloudflare tunnel token)\n' "$C_DIM" "$C_RESET" >&3
    ADDRESS_CHOICE=""
    read_tty -p "Choose 1 or 2: " ADDRESS_CHOICE || ADDRESS_CHOICE="2"
    [[ "$ADDRESS_CHOICE" == "1" ]] && FREE_ADDRESS=1
  fi
fi

if [[ -n "$FREE_ADDRESS" ]]; then
  if [[ -z "${LARAVELIFY_CLOUD_KEY:-}" ]]; then
    read_tty -s -p "Laravelify Cloud install key (app.laravelify.cloud → Dashboard → Install from a terminal): " LARAVELIFY_CLOUD_KEY || LARAVELIFY_CLOUD_KEY=""
    echo
  fi
  LARAVELIFY_CLOUD_KEY="$(printf '%s' "${LARAVELIFY_CLOUD_KEY:-}" | tr -d '[:space:]')"
  if [[ ! "$LARAVELIFY_CLOUD_KEY" =~ ^lfy_[A-Za-z0-9]{20,80}$ ]]; then
    echo "That is not a Laravelify Cloud install key. Copy it from app.laravelify.cloud → Dashboard → Install from a terminal." >&2
    exit 1
  fi
  install -d -m 700 /etc/laravelify
  if [[ ! -s /etc/laravelify/cloud-server-id ]]; then
    ( umask 077; tr -dc 'A-Za-z0-9' </dev/urandom | head -c 48 > /etc/laravelify/cloud-server-id || true )
  fi
  CLOUD_RESPONSE="$(curl -sS --max-time 60 -X POST -H 'Accept: application/json' \
    --data-urlencode "key=${LARAVELIFY_CLOUD_KEY}" \
    --data-urlencode "server_id=$(cat /etc/laravelify/cloud-server-id)" \
    --data-urlencode "name=$(hostname)" \
    -w '\n%{http_code}' "${CLOUD_URL}/api/install/panel" 2>&1 || true)"
  unset LARAVELIFY_CLOUD_KEY
  CLOUD_STATUS="${CLOUD_RESPONSE##*$'\n'}"
  CLOUD_BODY="${CLOUD_RESPONSE%$'\n'*}"
  cloud_field() {
    printf '%s' "$CLOUD_BODY" | python3 -c '
import json, sys
value = json.load(sys.stdin)
for key in sys.argv[1].split("."):
    value = value.get(key) if isinstance(value, dict) else None
print("" if value is None or value is False else value)' "$1" 2>/dev/null || true
  }
  if [[ "$CLOUD_STATUS" != "200" ]]; then
    CLOUD_MESSAGE="$(cloud_field message)"
    echo "Laravelify Cloud did not give a free address: ${CLOUD_MESSAGE:-${CLOUD_STATUS}}" >&2
    exit 1
  fi
  PANEL_DOMAIN="$(cloud_field panel_domain)"
  CLOUDFLARE_TUNNEL_TOKEN="$(cloud_field tunnel_token)"
  CLOUD_API="$(cloud_field cloud_api)"
  CLOUD_TOKEN="$(cloud_field cloud_token)"
  CLOUD_PREFIX="$(cloud_field prefix)"
  CLOUD_DOMAIN="$(cloud_field domain)"
  # Telegram alerts go out through Cloud's bot to the Telegram linked to the account.
  CLOUD_TELEGRAM_TOKEN="$(cloud_field telegram.token)"
  CLOUD_TELEGRAM_API="$(cloud_field telegram.api)"
  CLOUD_TELEGRAM_CHAT="$(cloud_field telegram.chat_id)"
  CLOUD_TELEGRAM_LINKED="$(cloud_field telegram.linked)"
  unset CLOUD_RESPONSE CLOUD_BODY
  # Cloud already routes the address and made its DNS record: no Cloudflare API token needed.
  CLOUDFLARE_API_TOKEN=""
  PANEL_ROUTED=1
  printf '%s│%s  %s✓ Free address%s https://%s\n' "$C_DIM" "$C_RESET" "$C_GREEN" "$C_RESET" "$PANEL_DOMAIN" >&3
fi

# The tunnel token comes first and is checked before anything else is asked: a token is base64 JSON
# with the account (a), the tunnel (t), and its secret (s). Whether it really connects is shown
# after the tunnel starts, near the end.
if [[ -z "${CLOUDFLARE_TUNNEL_TOKEN:-}" ]]; then
  read_tty -s -p "Cloudflare tunnel token: " CLOUDFLARE_TUNNEL_TOKEN
  echo
fi
CLOUDFLARE_TUNNEL_TOKEN="${CLOUDFLARE_TUNNEL_TOKEN#cloudflared service install }"
CLOUDFLARE_TUNNEL_TOKEN="${CLOUDFLARE_TUNNEL_TOKEN#sudo cloudflared service install }"

if [[ -z "$CLOUDFLARE_TUNNEL_TOKEN" || "$CLOUDFLARE_TUNNEL_TOKEN" =~ [[:space:]] ]]; then
  echo "The Cloudflare tunnel token is required and cannot contain spaces." >&2
  exit 1
fi

TUNNEL_TOKEN_JSON="$(printf '%s' "$CLOUDFLARE_TUNNEL_TOKEN" | base64 -d 2>/dev/null || true)"
TUNNEL_ID="$(printf '%s' "$TUNNEL_TOKEN_JSON" | grep -oP '"t"\s*:\s*"\K[0-9a-f-]{36}' || true)"
unset TUNNEL_TOKEN_JSON
if [[ -z "$TUNNEL_ID" ]]; then
  echo "That is not a Cloudflare tunnel token. Copy it from Zero Trust → Networks → Tunnels → your tunnel → Install connector (the long text after --token or 'service install')." >&2
  exit 1
fi
printf '%s│%s  %s✓ Tunnel token accepted%s (tunnel %s…)\n' "$C_DIM" "$C_RESET" "$C_GREEN" "$C_RESET" "${TUNNEL_ID:0:8}" >&3

# A tunnel token only connects; adding the panel's route and DNS record needs a Cloudflare API token
# (Account → Cloudflare Tunnel → Edit, Zone → DNS → Edit). Optional: Enter skips it, and the route is
# then added by hand in Cloudflare. It is also saved in the panel (Settings → Cloudflare).
if [[ -z "${CLOUDFLARE_API_TOKEN+set}" ]]; then
  read_tty -s -p "Cloudflare API token to add the panel route and DNS (optional, Enter to skip): " CLOUDFLARE_API_TOKEN || CLOUDFLARE_API_TOKEN=""
  echo
fi
CLOUDFLARE_API_TOKEN="$(printf '%s' "${CLOUDFLARE_API_TOKEN:-}" | tr -d '[:space:]')"
if [[ -n "$CLOUDFLARE_API_TOKEN" && ! "$CLOUDFLARE_API_TOKEN" =~ ^[A-Za-z0-9_-]{20,500}$ ]]; then
  echo "That is not a Cloudflare API token (letters, numbers, - and _ only). Run again, or press Enter to skip it." >&2
  exit 1
fi
if [[ -n "$CLOUDFLARE_API_TOKEN" ]]; then
  printf '%s│%s  %s✓ API token given%s: the panel route and DNS record are added for you\n' "$C_DIM" "$C_RESET" "$C_GREEN" "$C_RESET" >&3
fi

if [[ -z "${PANEL_DOMAIN:-}" ]]; then
  read_tty -p "Laravelify panel domain (example: panel.example.com): " PANEL_DOMAIN
fi

if [[ ! "$PANEL_DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
  echo "The panel domain is not a hostname." >&2
  exit 1
fi

# phpMyAdmin comes with MySQL or MariaDB only. By default it is a path of the panel's address
# (https://panel.example.com/phpmyadmin); --phpmyadmin-domain gives it a domain of its own instead.
PHPMYADMIN_URL=""
if [[ -n "$WITH_PHPMYADMIN" ]]; then
  if [[ -n "${PHPMYADMIN_DOMAIN:-}" && -z "${PHPMYADMIN_PATH:-}" ]]; then
    if [[ ! "$PHPMYADMIN_DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
      echo "The phpMyAdmin domain is not a hostname." >&2
      exit 1
    fi

    if [[ "$PANEL_DOMAIN" == "$PHPMYADMIN_DOMAIN" ]]; then
      echo "The panel domain and the phpMyAdmin domain must be different." >&2
      exit 1
    fi
    PHPMYADMIN_PATH=""
    PHPMYADMIN_URL="https://${PHPMYADMIN_DOMAIN}/"
  else
    PHPMYADMIN_DOMAIN=""
    PHPMYADMIN_PATH="${PHPMYADMIN_PATH:-/phpmyadmin}"
    PHPMYADMIN_PATH="/${PHPMYADMIN_PATH#/}"
    PHPMYADMIN_PATH="${PHPMYADMIN_PATH%/}"
    if [[ ! "$PHPMYADMIN_PATH" =~ ^/[a-z0-9][a-z0-9_-]{1,38}$ ]]; then
      echo "The phpMyAdmin path must look like /phpmyadmin: a slash, then letters, numbers, dashes and underscores." >&2
      exit 1
    fi
    PHPMYADMIN_URL="https://${PANEL_DOMAIN}${PHPMYADMIN_PATH}/"
  fi
else
  PHPMYADMIN_DOMAIN=""
  PHPMYADMIN_PATH=""
fi


# A re-run with a new database password: keep the old one, which MySQL still has, so the
# setup below can sign in with it and change it.
OLD_MYSQL_ROOT_PASSWORD="$( [[ -r "$STATE_FILE" ]] && . "$STATE_FILE" && printf '%s' "${PREV_MYSQL_ROOT_PASSWORD:-}" || true)"
# Older installs: the panel signs in to MySQL as root, so its .env has the password MySQL uses now.
OLD_PANEL_DB_PASSWORD=""
if grep -qx 'DB_USERNAME=root' "${LARAVELIFY_ROOT:-/opt/laravelify}/.env" 2>/dev/null; then
  OLD_PANEL_DB_PASSWORD="$(grep -m1 '^DB_PASSWORD=' "${LARAVELIFY_ROOT:-/opt/laravelify}/.env" | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//')"
fi

# Save the answers (root-only) so a re-run can offer them again.
install -d -m 700 /etc/laravelify
(
  umask 077
  printf 'PREV_MYSQL_ROOT_PASSWORD=%q\nPREV_PANEL_DOMAIN=%q\nPREV_PHPMYADMIN_DOMAIN=%q\nPREV_CLOUDFLARE_TUNNEL_TOKEN=%q\nPREV_DATABASES=%q\n' \
    "$MYSQL_ROOT_PASSWORD" "$PANEL_DOMAIN" "$PHPMYADMIN_DOMAIN" "$CLOUDFLARE_TUNNEL_TOKEN" "$DATABASES" > "$STATE_FILE"
)
chmod 600 "$STATE_FILE"

install -d -m 700 /tmp/laravelify-install
printf '%s' "$CLOUDFLARE_TUNNEL_TOKEN" > /tmp/laravelify-install/tunnel-token
chmod 600 /tmp/laravelify-install/tunnel-token
unset CLOUDFLARE_TUNNEL_TOKEN
if [[ -n "$CLOUDFLARE_API_TOKEN" ]]; then
  ( umask 077; printf '%s' "$CLOUDFLARE_API_TOKEN" > /tmp/laravelify-install/api-token )
fi
unset CLOUDFLARE_API_TOKEN

. /etc/os-release

ui_bar
printf '%s◇%s  %sSettings%s\n' "$C_CYAN" "$C_RESET" "$C_BOLD" "$C_RESET" >&3
printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "Server" "${PRETTY_NAME:-Ubuntu}" >&3
printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "Panel" "https://${PANEL_DOMAIN}" >&3
if [[ -n "$WITH_PHPMYADMIN" ]]; then
  printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "phpMyAdmin" "${PHPMYADMIN_URL}" >&3
fi
printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "PHP-FPM" "${PHP_VERSIONS}" >&3
printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "Node.js" "${NODE_VERSIONS} (system: ${NODE_BASE})" >&3
DATABASE_LABELS="$(sed 's/ /, /g' <<<"$DATABASES" | sed -e 's/mysql/MySQL 8.4/' -e 's/mariadb/MariaDB/' -e 's/postgresql/PostgreSQL/' -e 's/mongodb/MongoDB/')"
printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "Databases" "${DATABASE_LABELS:+${DATABASE_LABELS}, }SQLite" >&3
printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "Also" "Redis, nginx,${WITH_PHPMYADMIN:+ phpMyAdmin,} PM2, Composer, cloudflared" >&3
printf '%s│%s  %-20s %s\n' "$C_DIM" "$C_RESET" "Log" "${LOG_FILE}" >&3
if [[ -n "${PHP_ADDED_NOTE:-}" ]]; then
  printf '%s│%s  %s%s%s\n' "$C_DIM" "$C_RESET" "$C_YELLOW" "$PHP_ADDED_NOTE" "$C_RESET" >&3
fi
ui_bar

# Get the panel first: later steps use its helper scripts, and a bad download
# should fail now, not after ten minutes of package installs.
APP_ROOT="/opt/laravelify"
SOURCE=""
step "Downloading Laravelify"
if [[ -n "${LARAVELIFY_SOURCE:-}" && -f "${LARAVELIFY_SOURCE}/artisan" ]]; then
  SOURCE="$LARAVELIFY_SOURCE"
elif [[ -n "${BASH_SOURCE[0]:-}" && -f "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/artisan" ]]; then
  SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
else
  # curl | bash: download the release (built assets included, vendor installed below).
  ARCHIVE_URL="${LARAVELIFY_ARCHIVE_URL:-$DEFAULT_ARCHIVE_URL}"
  echo "Downloading Laravelify from ${ARCHIVE_URL}"
  install -d "$APP_ROOT"
  if ! curl -fsSL "$ARCHIVE_URL" | tar -xz -C "$APP_ROOT" --strip-components=1; then
    echo "Could not download ${ARCHIVE_URL}." >&2
    echo "Check LARAVELIFY_REPO / LARAVELIFY_VERSION, or that the GitHub release has laravelify.tar.gz attached." >&2
    exit 1
  fi
fi

if [[ -n "$SOURCE" && "$SOURCE" != "$APP_ROOT" ]]; then
  install -d "$APP_ROOT"
  # Never the source's own .env (another machine's APP_KEY would make every saved secret
  # unreadable), its data, or its caches: the server keeps its own.
  tar -C "$SOURCE" --exclude=vendor --exclude=node_modules --exclude=./storage --exclude=./bootstrap/cache \
    --exclude=./.env --exclude='./.env.*.local' --exclude=./.env.backup --exclude=.git -cf - . | tar -C "$APP_ROOT" -xf -
  install -d "$APP_ROOT/storage/app" "$APP_ROOT/storage/framework/cache" "$APP_ROOT/storage/framework/sessions" \
    "$APP_ROOT/storage/framework/views" "$APP_ROOT/storage/logs" "$APP_ROOT/bootstrap/cache"
fi

if [[ ! -f "$APP_ROOT/artisan" ]]; then
  echo "The panel source was not found."
  echo "Re-run with LARAVELIFY_SOURCE=/path/to/laravelify, LARAVELIFY_VERSION=x.y.z, or LARAVELIFY_ARCHIVE_URL=https://..."
  exit 1
fi

PANEL_VERSION="$(tr -d '\r\n' < "$APP_ROOT/VERSION" 2>/dev/null || echo '')"
STEP_LABEL="Downloaded Laravelify ${PANEL_VERSION}"

CODENAME="${VERSION_CODENAME}"

# apt-get install only for the packages that are not installed yet (quiet when all are).
package_installed() {
  dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q 'install ok installed'
}

apt_install() {
  local missing=() package
  for package in "$@"; do
    package_installed "$package" || missing+=("$package")
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    apt-get install -y "${missing[@]}"
  fi
}

drop_extra_sources() {
  local pattern="$1"
  local file
  shopt -s nullglob
  for file in /etc/apt/sources.list.d/*; do
    [[ -f "$file" ]] || continue
    if grep -q "$pattern" "$file"; then
      echo "Removing apt source ${file}"
      rm -f "$file"
    fi
  done
}

# A previous run may have added the Ondřej PPA. Ubuntu 26.04 has no Release
# file there, and apt then refuses every update.
if awk -v v="${VERSION_ID:-0}" 'BEGIN { exit !(v+0 >= 26.04) }'; then
  drop_extra_sources "ppa.launchpadcontent.net/ondrej/php"
fi

step "Installing nginx, Redis, Supervisor, Git, and tools"
apt-get update
apt_install ca-certificates curl gnupg lsb-release software-properties-common \
  nginx supervisor git unzip zip acl redis-server ufw debconf-utils rclone

# The PHP repository is added once.
php_repository_present() {
  grep -rqsE 'ondrej/php|packages\.sury\.org/php' /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null
}

if ! php_repository_present; then
  step "Adding the PHP package repository"
  if awk -v v="${VERSION_ID:-0}" 'BEGIN { exit !(v+0 >= 26.04) }'; then
    curl -fsSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    dpkg -i /tmp/debsuryorg-archive-keyring.deb
    rm -f /tmp/debsuryorg-archive-keyring.deb
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${CODENAME} main" > /etc/apt/sources.list.d/php.list
  else
    add-apt-repository -y ppa:ondrej/php
  fi
  apt-get update
fi

install_php() {
  local version="$1"
  local packages=()
  local extension

  # pgsql and mongodb only with their servers. mysql always: the panel's tools use it.
  for extension in fpm cli mysql xml mbstring curl zip redis bcmath intl gd sqlite3 \
    $(has_database postgresql && echo pgsql) $(has_database mongodb && echo mongodb); do
    packages+=("php${version}-${extension}")
  done

  local missing=()
  local package
  for package in "${packages[@]}"; do
    package_installed "$package" || missing+=("$package")
  done

  if [[ ${#missing[@]} -eq 0 ]]; then
    STEP_LABEL="PHP ${version} is already installed"
  elif apt-cache show "php${version}-fpm" >/dev/null 2>&1; then
    apt-get install -y "${missing[@]}"
  else
    note "Skipping PHP ${version}: the PHP repository has no packages for ${PRETTY_NAME:-this Ubuntu}."
    STEP_LABEL="PHP ${version} skipped"
  fi
}

for version in $PHP_VERSIONS; do
  step "Installing PHP ${version} (FPM, CLI, database drivers, Redis, GD, intl, and more)"
  install_php "${version}"
done

# The panel runs on PHP 8.4 or 8.5 from this install. 8.3 is too old for the
# locked Symfony 8.1 packages. A PHP already on the server is not used: its
# CLI often lacks ext-dom.
PANEL_PHP=""
for version in 8.4 8.5; do
  if [[ " $PHP_VERSIONS " == *" $version "* && -x "/usr/bin/php${version}" ]]; then
    PANEL_PHP="$version"
    break
  fi
done
if [[ -z "$PANEL_PHP" ]]; then
  for version in 8.4 8.3 8.5 8.2 8.1 8.0; do
    if [[ -x "/usr/bin/php${version}" ]]; then
      PANEL_PHP="$version"
      break
    fi
  done
fi

if [[ -z "$PANEL_PHP" ]]; then
  echo "No PHP CLI was installed." >&2
  exit 1
fi

PHP_BIN="/usr/bin/php${PANEL_PHP}"
# Ondrej registers each PHP at priority 80+minor (php8.5 is 85). A priority of
# 84 leaves that binary as `php`, and Composer's shebang follows it.
update-alternatives --install /usr/bin/php php "$PHP_BIN" 100 || true
update-alternatives --set php "$PHP_BIN" || true
if ! "$PHP_BIN" -m | grep -qx dom; then
  apt-get install -y "php${PANEL_PHP}-xml"
fi

install_mysql() {
  if package_installed mariadb-server; then
    echo "MariaDB is already installed on this server: MySQL cannot be installed next to it. Use --databases=mariadb." >&2
    exit 1
  fi
  if package_installed mysql-community-server || package_installed mysql-server; then
    STEP_LABEL="MySQL is already installed"
    return 0
  fi

  install -d -m 0755 /usr/share/keyrings
  if [[ ! -f /usr/share/keyrings/mysql-archive-keyring.gpg ]]; then
    curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 | gpg --dearmor -o /usr/share/keyrings/mysql-archive-keyring.gpg
  fi

  cat > /etc/apt/sources.list.d/mysql-8.4-lts.list <<EOF
deb [signed-by=/usr/share/keyrings/mysql-archive-keyring.gpg] http://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-8.4-lts
EOF

  if ! apt-get update; then
    note "MySQL 8.4 has no packages for ${CODENAME}: using Ubuntu's MySQL package."
    rm -f /etc/apt/sources.list.d/mysql-8.4-lts.list
    apt-get update
    apt-get install -y mysql-server
  else
    apt-get install -y mysql-community-server mysql-community-client || apt-get install -y mysql-server
  fi
}

install_mariadb() {
  if package_installed mysql-community-server || package_installed mysql-server; then
    echo "MySQL is already installed on this server: MariaDB cannot be installed next to it. Use --databases=mysql." >&2
    exit 1
  fi
  if package_installed mariadb-server; then
    STEP_LABEL="MariaDB is already installed"
    return 0
  fi
  apt-get install -y mariadb-server mariadb-client
}

# Without MySQL or MariaDB there is nothing to install here: the panel keeps its data in SQLite.
if [[ "$MYSQL_FAMILY" == "mysql" ]]; then
  step "Installing MySQL 8.4"
  install_mysql
elif [[ "$MYSQL_FAMILY" == "mariadb" ]]; then
  step "Installing MariaDB"
  install_mariadb
fi

# Ubuntu 26.04 rejects debconf answers for templates that are not loaded yet.
preseed_phpmyadmin() {
  apt-get install -y -d phpmyadmin || return 0
  local deb tmp
  deb="$(find /var/cache/apt/archives -maxdepth 1 -name 'phpmyadmin_*.deb' -print -quit 2>/dev/null || true)"
  [[ -n "$deb" ]] || return 0
  tmp="$(mktemp -d)"
  if dpkg-deb -e "$deb" "$tmp" && [[ -f "$tmp/templates" ]]; then
    debconf-loadtemplate phpmyadmin "$tmp/templates" || true
    if grep -q '^Template: phpmyadmin/dbconfig-install$' "$tmp/templates"; then
      echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    fi
    if grep -q '^Template: phpmyadmin/reconfigure-webserver$' "$tmp/templates"; then
      echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect" | debconf-set-selections
    fi
  fi
  rm -rf "$tmp"
}
if [[ -n "$WITH_PHPMYADMIN" ]]; then
  step "Installing phpMyAdmin"
  if package_installed phpmyadmin; then
    STEP_LABEL="phpMyAdmin is already installed"
  else
    preseed_phpmyadmin
    apt-get install -y phpmyadmin || note "phpMyAdmin was not installed; nginx still points at /usr/share/phpmyadmin."
  fi
fi

step "Installing Node.js ${NODE_BASE}, npm, and PM2"
if ! command -v node >/dev/null 2>&1; then
  if curl -fsSL "https://deb.nodesource.com/setup_${NODE_BASE}.x" | bash -; then
    apt-get install -y nodejs
  else
    note "NodeSource does not support ${CODENAME}: using Ubuntu's Node.js package."
    drop_extra_sources "deb.nodesource.com"
    apt-get update
    apt-get install -y nodejs npm
  fi
fi

if ! command -v pm2 >/dev/null 2>&1 || ! command -v n >/dev/null 2>&1; then
  npm install -g pm2 n
fi
# Node sites run in www-data's PM2 daemon; scripts/privileges.sh enables pm2-www-data below.
for node_major in $NODE_VERSIONS; do
  step "Installing Node.js ${node_major}"
  if compgen -G "/usr/local/n/versions/node/${node_major}.*" >/dev/null; then
    STEP_LABEL="Node.js ${node_major} is already installed"
  elif ! n "$node_major"; then
    note "Could not install Node.js ${node_major}; sites set to it use Node.js ${NODE_BASE}."
  fi
done
# `n` switches the system Node.js to each version it installs: end on the system one.
n "$NODE_BASE" || true

# Our own Composer, not another one on the PATH (WSL also sees Windows programs).
if [[ ! -x /usr/local/bin/composer ]]; then
  step "Installing Composer"
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  "$PHP_BIN" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

install -d -m 700 /tmp/laravelify-install
printf '%s' "$MYSQL_ROOT_PASSWORD" > /tmp/laravelify-install/password
chmod 600 /tmp/laravelify-install/password

if [[ -n "$MYSQL_FAMILY" ]]; then
  if [[ "$MYSQL_FAMILY" == "mariadb" ]]; then
    step "Setting up MariaDB (root password, utf8mb4)"
  else
    step "Setting up MySQL (root password, utf8mb4)"
  fi
  # MariaDB has no caching_sha2_password: its default password plugin is used.
  MYSQL_FAMILY="$MYSQL_FAMILY" "$PHP_BIN" <<'PHP'
<?php
$password = file_get_contents('/tmp/laravelify-install/password');
$quoted = str_replace(['\\', '"'], ['\\\\', '\\"'], $password);
file_put_contents('/tmp/laravelify-install/client.cnf', "[client]\nuser=root\npassword=\"{$quoted}\"\n");
$escaped = str_replace("'", "''", $password);
$with = getenv('MYSQL_FAMILY') === 'mariadb' ? '' : 'WITH caching_sha2_password ';
file_put_contents('/tmp/laravelify-install/setup.sql', <<<SQL
ALTER USER 'root'@'localhost' IDENTIFIED {$with}BY '{$escaped}';
CREATE USER IF NOT EXISTS 'root'@'127.0.0.1' IDENTIFIED {$with}BY '{$escaped}';
ALTER USER 'root'@'127.0.0.1' IDENTIFIED {$with}BY '{$escaped}';
GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS laravelify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL);
PHP

  if mysql --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
    mysql --protocol=socket -uroot < /tmp/laravelify-install/setup.sql
  elif mysql --defaults-extra-file=/tmp/laravelify-install/client.cnf --protocol=socket -e "SELECT 1" >/dev/null 2>&1; then
    mysql --defaults-extra-file=/tmp/laravelify-install/client.cnf --protocol=socket < /tmp/laravelify-install/setup.sql
  elif [[ -n "$OLD_MYSQL_ROOT_PASSWORD" ]] && MYSQL_PWD="$OLD_MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
    # Installed before with another password: sign in with the old one and set the new one.
    MYSQL_PWD="$OLD_MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot < /tmp/laravelify-install/setup.sql
  elif [[ -n "$OLD_PANEL_DB_PASSWORD" ]] && MYSQL_PWD="$OLD_PANEL_DB_PASSWORD" mysql --protocol=socket -uroot -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_PWD="$OLD_PANEL_DB_PASSWORD" mysql --protocol=socket -uroot < /tmp/laravelify-install/setup.sql
  else
    echo "MySQL already has a root password that is not the one given. Run the installer again with the old password as MYSQL_ROOT_PASSWORD." >&2
    exit 1
  fi

  if [[ -f /etc/phpmyadmin/config-db.php && -f /usr/share/phpmyadmin/sql/create_tables.sql ]]; then
    "$PHP_BIN" "$APP_ROOT/scripts/configure-phpmyadmin.php" /tmp/laravelify-install/password
  fi

  if [[ "$MYSQL_FAMILY" == "mariadb" ]]; then
    install -d /etc/mysql/mariadb.conf.d
    cat > /etc/mysql/mariadb.conf.d/99-laravelify.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
port = 3306
character-set-server = utf8mb4
collation-server = utf8mb4_general_ci
# Lets site users create triggers when binary logging is on (error 1419).
log_bin_trust_function_creators = 1
EOF
    systemctl restart mariadb
  else
    install -d /etc/mysql/mysql.conf.d
    cat > /etc/mysql/mysql.conf.d/laravelify.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
mysqlx = 0
port = 3306
character-set-server = utf8mb4
collation-server = utf8mb4_general_ci
# Binary logging is on in MySQL 8: without this, site users cannot create triggers (error 1419).
log_bin_trust_function_creators = 1
EOF
    systemctl restart mysql
    mysql --defaults-extra-file=/tmp/laravelify-install/client.cnf --protocol=socket -e "SET PERSIST default_collation_for_utf8mb4 = 'utf8mb4_general_ci'; SET PERSIST log_bin_trust_function_creators = 1"
  fi

  install -d /etc/phpmyadmin/conf.d
  cat > /etc/phpmyadmin/conf.d/laravelify-collation.php <<'EOF'
<?php
$cfg['DefaultCharset'] = 'utf8mb4';
$cfg['DefaultConnectionCollation'] = 'utf8mb4_general_ci';
EOF
fi

# PostgreSQL from Ubuntu's packages: the postgres user gets the database password, and the
# server answers on 127.0.0.1 only (its default), with passwords (scram-sha-256).
if has_database postgresql; then
  step "Installing PostgreSQL"
  apt_install postgresql postgresql-contrib
  systemctl enable --now postgresql
  (cd /tmp && sudo -u postgres psql -q -v ON_ERROR_STOP=1 -v password="$MYSQL_ROOT_PASSWORD" <<'SQL'
ALTER USER postgres WITH PASSWORD :'password';
SQL
  )
fi

# MongoDB from its own repository (Ubuntu has no package), with sign-in turned on: an admin
# user with the database password, and the server on 127.0.0.1 only.
if has_database mongodb; then
  step "Installing MongoDB"
  if ! command -v mongod >/dev/null 2>&1; then
    install -d -m 0755 /usr/share/keyrings
    curl -fsSL https://pgp.mongodb.com/server-8.0.asc | gpg --dearmor --yes -o /usr/share/keyrings/mongodb-server-8.0.gpg
    MONGO_CODENAME="$CODENAME"
    # MongoDB publishes packages for LTS releases some time after they come out: use the
    # newest one it has.
    if ! curl -fsI "https://repo.mongodb.org/apt/ubuntu/dists/${MONGO_CODENAME}/mongodb-org/8.0/Release" >/dev/null; then
      MONGO_CODENAME="noble"
      note "MongoDB has no packages for ${CODENAME} yet: using the ones for Ubuntu 24.04 (noble)."
    fi
    echo "deb [arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-8.0.gpg] https://repo.mongodb.org/apt/ubuntu ${MONGO_CODENAME}/mongodb-org/8.0 multiverse" \
      > /etc/apt/sources.list.d/mongodb-org-8.0.list
    apt-get update
    apt-get install -y mongodb-org
  fi
  systemctl enable --now mongod
  for _ in $(seq 1 30); do
    mongosh --quiet --eval 'db.runCommand({ ping: 1 })' >/dev/null 2>&1 && break
    sleep 1
  done
  # The admin user is made while sign-in is still off (the first run); later runs sign in.
  MONGO_PASSWORD="$MYSQL_ROOT_PASSWORD" mongosh --quiet admin --eval '
    const password = process.env.MONGO_PASSWORD;
    if (db.getUser("admin")) { db.changeUserPassword("admin", password); }
    else { db.createUser({ user: "admin", pwd: password, roles: ["root"] }); }
  ' >/dev/null 2>&1 \
    || MONGO_PASSWORD="$MYSQL_ROOT_PASSWORD" mongosh --quiet -u admin -p "$MYSQL_ROOT_PASSWORD" --authenticationDatabase admin admin \
      --eval 'db.changeUserPassword("admin", process.env.MONGO_PASSWORD)' >/dev/null
  if ! grep -q '^security:' /etc/mongod.conf; then
    printf '\nsecurity:\n  authorization: enabled\n' >> /etc/mongod.conf
  fi
  sed -i 's/^\(\s*bindIp:\).*/\1 127.0.0.1/' /etc/mongod.conf
  systemctl restart mongod
fi

step "Configuring Redis"
install -d /etc/redis
cat > /etc/redis/laravelify.conf <<'EOF'
bind 127.0.0.1
port 6379
supervised systemd
appendonly yes
EOF
if [[ -f /etc/redis/redis.conf ]] && ! grep -q '/etc/redis/laravelify.conf' /etc/redis/redis.conf; then
  printf '\ninclude /etc/redis/laravelify.conf\n' >> /etc/redis/redis.conf
fi
systemctl restart redis-server

if [[ -n "$WITH_PHPMYADMIN" && -n "$PHPMYADMIN_PATH" ]]; then
step "Configuring phpMyAdmin at ${PHPMYADMIN_PATH}"
# A path of the panel's address: the panel's nginx site includes /etc/nginx/laravelify-panel.d/*.conf.
rm -f /etc/nginx/sites-enabled/laravelify-phpmyadmin.conf /etc/nginx/sites-available/laravelify-phpmyadmin.conf
install -d -m 0755 /etc/nginx/laravelify-panel.d
cat > /etc/nginx/laravelify-panel.d/phpmyadmin.conf <<EOF
# phpMyAdmin at ${PHPMYADMIN_PATH} (changed from the panel: Server → phpMyAdmin).
location = ${PHPMYADMIN_PATH} {
    return 301 ${PHPMYADMIN_PATH}/;
}

location ^~ ${PHPMYADMIN_PATH}/ {
    alias /usr/share/phpmyadmin/;
    index index.php;
    client_max_body_size 256M;

    location ~ \.php\$ {
        if (!-f \$request_filename) { return 404; }
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$request_filename;
        fastcgi_pass unix:/run/php/php${PANEL_PHP}-fpm.sock;
        fastcgi_read_timeout 600;
        fastcgi_param PHP_VALUE "upload_max_filesize=256M
post_max_size=256M
memory_limit=512M
max_execution_time=600";
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
elif [[ -n "$WITH_PHPMYADMIN" ]]; then
step "Configuring phpMyAdmin"
rm -f /etc/nginx/laravelify-panel.d/phpmyadmin.conf
cat > /etc/nginx/sites-available/laravelify-phpmyadmin.conf <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${PHPMYADMIN_DOMAIN};

    root /usr/share/phpmyadmin;
    index index.php;
    # Big imports (database dumps): nginx and PHP accept up to 256 MB and run up to 10 minutes.
    client_max_body_size 256M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PANEL_PHP}-fpm.sock;
        fastcgi_read_timeout 600;
        fastcgi_param PHP_VALUE "upload_max_filesize=256M
post_max_size=256M
memory_limit=512M
max_execution_time=600";
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF
ln -sfn /etc/nginx/sites-available/laravelify-phpmyadmin.conf /etc/nginx/sites-enabled/laravelify-phpmyadmin.conf
fi


step "Installing the panel (Composer packages, database)"
if [[ ! -f "$APP_ROOT/.env" ]]; then
  cp "$APP_ROOT/.env.example" "$APP_ROOT/.env"
fi

if [[ ! -s /tmp/laravelify-install/setup-token ]]; then
  existing_token="$(grep -E '^LARAVELIFY_SETUP_TOKEN=' "$APP_ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
  printf '%s' "${existing_token:-$(openssl rand -hex 20)}" > /tmp/laravelify-install/setup-token
  chmod 600 /tmp/laravelify-install/setup-token
fi
SETUP_TOKEN="$(cat /tmp/laravelify-install/setup-token)"

PANEL_DOMAIN="$PANEL_DOMAIN" MYSQL_FAMILY="$MYSQL_FAMILY" DATABASES="$DATABASES" WITH_GLITCHTIP="$WITH_GLITCHTIP" "$PHP_BIN" <<'PHP'
<?php
$path = "/opt/laravelify/.env";
// The panel's own data: in MySQL or MariaDB when there is one, otherwise in SQLite (a file in
// storage, which the panel's user owns and every update keeps).
$mysql = getenv("MYSQL_FAMILY") !== "";
$sqlite = "/opt/laravelify/storage/app/laravelify.sqlite";
if (! $mysql && ! file_exists($sqlite)) {
  touch($sqlite);
}
$databases = trim(getenv("DATABASES")." sqlite");
$password = file_get_contents("/tmp/laravelify-install/password");
$setupToken = trim((string) @file_get_contents("/tmp/laravelify-install/setup-token"));
$values = [
  "APP_ENV" => "production",
  "APP_DEBUG" => "false",
  "APP_URL" => "https://".getenv("PANEL_DOMAIN"),
  "DB_CONNECTION" => $mysql ? "mysql" : "sqlite",
  "DB_HOST" => "127.0.0.1",
  "DB_PORT" => "3306",
  "DB_DATABASE" => $mysql ? "laravelify" : $sqlite,
  "DB_USERNAME" => "root",
  "DB_PASSWORD" => $password,
  // Which database engines websites can use, and the logins the panel makes their databases
  // with (MySQL and MariaDB use the DB_ login above).
  "LARAVELIFY_DATABASES" => '"'.$databases.'"',
  // GlitchTip was picked at install: the panel installs it once its admin exists.
  "LARAVELIFY_INSTALL_GLITCHTIP" => getenv("WITH_GLITCHTIP") ? "true" : "false",
  "LARAVELIFY_POSTGRES_PASSWORD" => str_contains($databases, "postgresql") ? $password : "",
  "LARAVELIFY_MONGODB_PASSWORD" => str_contains($databases, "mongodb") ? $password : "",
  "SESSION_DRIVER" => "database",
  "QUEUE_CONNECTION" => "database",
  "CACHE_STORE" => "database",
  "REDIS_CLIENT" => "phpredis",
  "REDIS_HOST" => "127.0.0.1",
  "REDIS_PASSWORD" => "null",
  "REDIS_PORT" => "6379",
  "LARAVELIFY_HOST_COMMANDS" => "true",
  // Only the person who ran the installer can create the superadmin: /setup needs this token.
  "LARAVELIFY_SETUP_TOKEN" => $setupToken,
];
$contents = file_get_contents($path);
foreach ($values as $key => $value) {
  if (in_array($key, ["DB_PASSWORD", "LARAVELIFY_POSTGRES_PASSWORD", "LARAVELIFY_MONGODB_PASSWORD"], true)) {
    $value = '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
  }
  $line = $key."=".$value;
  if (preg_match("/^".preg_quote($key, "/")."=.*/m", $contents)) {
    $contents = preg_replace("/^".preg_quote($key, "/")."=.*/m", $line, $contents, 1);
  } else {
    $contents = rtrim($contents)."\n".$line."\n";
  }
}
file_put_contents($path, $contents);
PHP

cd "$APP_ROOT"
export COMPOSER_ALLOW_SUPERUSER=1
"$PHP_BIN" /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-ansi
if ! grep -q '^APP_KEY=base64:' .env; then
  "$PHP_BIN" artisan key:generate --force --no-ansi
fi
"$PHP_BIN" artisan migrate --force --no-ansi

step "Saving the server settings and the tunnel"
PHPMYADMIN_DOMAIN="$PHPMYADMIN_DOMAIN" PHPMYADMIN_PATH="$PHPMYADMIN_PATH" PANEL_DOMAIN="$PANEL_DOMAIN" PANEL_PHP="$PANEL_PHP" MYSQL_FAMILY="$MYSQL_FAMILY" "$PHP_BIN" <<'PHP'
<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$domain = getenv("PHPMYADMIN_DOMAIN");
$password = file_get_contents("/tmp/laravelify-install/password");
$php = getenv("PANEL_PHP");
foreach (getenv("MYSQL_FAMILY") !== "" ? ["mysql", "redis", "phpmyadmin"] : ["redis"] as $key) {
  $service = App\Models\ServerService::query()->where("key", $key)->first();
  if (! $service) {
    continue;
  }
  if ($key === "phpmyadmin") {
    // At a path of the panel's address, or on a domain of its own.
    $path = (string) getenv("PHPMYADMIN_PATH");
    if ($path !== "") {
      $service->domain = getenv("PANEL_DOMAIN").$path;
      $service->settings = ["php_version" => $php, "path" => $path];
      $service->config = App\Services\ServerCatalog::phpMyAdminPathNginx($path, "unix:/run/php/php{$php}-fpm.sock");
      $service->save();
      file_put_contents("/etc/nginx/laravelify-panel.d/phpmyadmin.conf", $service->config);
      continue;
    }
    $service->domain = $domain;
    $service->config = preg_replace("/server_name\s+[^;]+;/", "server_name {$domain};", $service->config, 1);
    $service->config = preg_replace("#unix:/run/php/php[0-9.]+-fpm\\.sock#", "unix:/run/php/php{$php}-fpm.sock", $service->config, 1);
    $service->settings = ["php_version" => $php];
  }
  if ($key === "mysql") {
    $service->domain = "127.0.0.1";
    $service->settings = ["bind" => "127.0.0.1", "port" => "3306", "username" => "root", "password" => $password];
  }
  if ($key === "redis") {
    $service->domain = "127.0.0.1";
    $service->settings = ["bind" => "127.0.0.1", "port" => "6379", "password" => ""];
  }
  $service->save();
}
$panel = getenv("PANEL_DOMAIN");
$phpmyadmin = getenv("PHPMYADMIN_DOMAIN");
$slug = strtolower(str_replace(".", "-", $panel));
$token = trim(file_get_contents("/tmp/laravelify-install/tunnel-token"));
$token = str_replace(["\r", "\n", "\""], "", $token);
$ingress = implode("\n", array_merge(
  ["ingress:", "  - hostname: {$panel}", "    service: http://127.0.0.1:80"],
  // phpMyAdmin's domain only with MySQL or MariaDB.
  $phpmyadmin !== "" ? ["  - hostname: {$phpmyadmin}", "    service: http://127.0.0.1:80"] : [],
  ["  - service: http_status:404"],
))."\n";
App\Models\CloudflareTunnel::query()->updateOrCreate(
  ["slug" => $slug],
  ["name" => $panel, "token" => $token, "ingress" => $ingress],
);
file_put_contents("/tmp/laravelify-install/tunnel-slug", $slug);
$unit = <<<UNIT
[Unit]
Description=Laravelify Cloudflare tunnel {$slug}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/cloudflared tunnel --no-autoupdate run --token "{$token}"
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT;
file_put_contents("/etc/systemd/system/laravelify-tunnel-{$slug}.service", $unit);
chmod("/etc/systemd/system/laravelify-tunnel-{$slug}.service", 0600);
PHP
if [[ ! -s /tmp/laravelify-install/tunnel-slug ]]; then
  echo "The server settings and the tunnel were not saved (see the error above)." >&2
  exit 1
fi

step "Configuring nginx for the panel"
install -d -m 0755 /etc/nginx/laravelify-panel.d
cat > /etc/nginx/sites-available/laravelify-panel.conf <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name ${PANEL_DOMAIN};

    root ${APP_ROOT}/public;
    index index.php;
    # Big uploads in the file manager: nginx and PHP accept up to 256 MB.
    client_max_body_size 256M;

    # Website previews at /p/<token>/ (scripts/host-apply.sh panel-preview).
    include /etc/nginx/laravelify-panel.d/*.conf;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${PANEL_PHP}-fpm.sock;
        fastcgi_read_timeout 600;
        fastcgi_param PHP_VALUE "upload_max_filesize=256M
post_max_size=256M
memory_limit=512M
max_execution_time=600";
    }

    location ~ /\\.(?!well-known).* {
        deny all;
    }
}
EOF
ln -sfn /etc/nginx/sites-available/laravelify-panel.conf /etc/nginx/sites-enabled/laravelify-panel.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

step "Creating users and permissions (panel, websites, PM2)"
install -d -o www-data -g www-data -m 0755 /var/www
chmod 755 "$APP_ROOT"/scripts/*.sh

# The panel's own user (laravelify) with its own PHP-FPM pool, queue worker, and scheduler;
# every website gets its own user (lfy-<slug>) and cannot read the panel or other websites. Also writes the sudo rule and Supervisor
# programs, and sets the owners and permissions of the panel's files.
LARAVELIFY_ROOT="$APP_ROOT" LARAVELIFY_PHP="php${PANEL_PHP}" bash "$APP_ROOT/scripts/privileges.sh"
bash "$APP_ROOT/scripts/host-apply.sh" phpmyadmin-hide all || true

if has_tool vscode; then
step "Installing VS Code for the websites (code-server)"
# Downloaded once here, so the code editor opens at once the first time. graphify (the AI
# agents' project map) comes with it.
bash "$APP_ROOT/scripts/host-apply.sh" code-server-install >/dev/null \
  || echo "VS Code could not be installed now; it installs the first time someone opens the editor." >&2
fi

step "Starting the Cloudflare tunnel"
if ! command -v cloudflared >/dev/null 2>&1; then
  case "$(uname -m)" in
    x86_64|amd64) CLOUDFLARED_ARCH="amd64" ;;
    aarch64|arm64) CLOUDFLARED_ARCH="arm64" ;;
    *)
      echo "cloudflared has no build for $(uname -m)." >&2
      exit 1
      ;;
  esac
  curl -fsSL -o /tmp/cloudflared.deb "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${CLOUDFLARED_ARCH}.deb"
  dpkg -i /tmp/cloudflared.deb || apt-get install -f -y
  rm -f /tmp/cloudflared.deb
fi

TUNNEL_SLUG="$(tr -d '\r\n' < /tmp/laravelify-install/tunnel-slug)"
install -d /etc/cloudflared
systemctl daemon-reload
systemctl enable --now "laravelify-tunnel-${TUNNEL_SLUG}.service"
# Show whether the tunnel reached Cloudflare: cloudflared logs "Registered tunnel connection" per edge.
TUNNEL_CONNECTED=""
for _ in $(seq 1 30); do
  if journalctl -u "laravelify-tunnel-${TUNNEL_SLUG}.service" --since "-2min" --no-pager 2>/dev/null | grep -c "Registered tunnel connection" >/dev/null; then
    TUNNEL_CONNECTED=1
    break
  fi
  sleep 1
done
if [[ -n "$TUNNEL_CONNECTED" ]]; then
  STEP_LABEL="Cloudflare tunnel connected"
else
  printf '%s│%s  %s! The tunnel did not connect to Cloudflare in 30 seconds.%s Check: journalctl -u laravelify-tunnel-%s\n' "$C_DIM" "$C_RESET" "$C_YELLOW" "$C_RESET" "$TUNNEL_SLUG" >&3
fi

PANEL_ROUTED="${PANEL_ROUTED:-}"
if [[ -n "$CLOUD_TOKEN" ]]; then
  # Websites on this panel can then use free <client>-<name>.laravelify.cloud domains too.
  (cd "$APP_ROOT" && LARAVELIFY_CLOUD_TOKEN="$CLOUD_TOKEN" "$PHP_BIN" artisan laravelify:cloud-connect "$CLOUD_API" --prefix="$CLOUD_PREFIX" --domain="$CLOUD_DOMAIN" --no-ansi) || true
  CLOUD_TOKEN=""
  if [[ -n "${CLOUD_TELEGRAM_TOKEN:-}" ]]; then
    (cd "$APP_ROOT" && LARAVELIFY_TELEGRAM_TOKEN="$CLOUD_TELEGRAM_TOKEN" "$PHP_BIN" artisan laravelify:telegram-connect "${CLOUD_TELEGRAM_CHAT:-0}" --api="$CLOUD_TELEGRAM_API" --no-ansi) || true
    CLOUD_TELEGRAM_TOKEN=""
  fi
fi
if [[ -s /tmp/laravelify-install/api-token ]]; then
  step "Adding the panel route and DNS record in Cloudflare"
  if (cd "$APP_ROOT" && "$PHP_BIN" artisan laravelify:panel-route --token-file=/tmp/laravelify-install/api-token --no-ansi); then
    PANEL_ROUTED=1
  else
    printf '%s│%s  %s! Cloudflare did not take the panel route.%s Add it by hand (below); the reason is in %s\n' "$C_DIM" "$C_RESET" "$C_YELLOW" "$C_RESET" "$LOG_FILE" >&3
  fi
  rm -f /tmp/laravelify-install/api-token
fi

step "Turning on the firewall (SSH, 80, 443)"
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

step_done
exec 1>&3 2>&4

ui_bar
printf '%s└%s  %sLaravelify %s is installed%s %s(%s)%s\n\n' "$C_DIM" "$C_RESET" "$C_GREEN$C_BOLD" "${PANEL_VERSION}" "$C_RESET" "$C_DIM" "$(duration $((SECONDS - INSTALL_START)))" "$C_RESET"
printf '  %sNext steps%s\n\n' "$C_BOLD" "$C_RESET"
printf '  %s1.%s Create the superadmin (only this link opens the setup page):\n' "$C_CYAN" "$C_RESET"
printf '     %s%shttps://%s/setup?token=%s%s\n\n' "$C_BOLD" "$C_GREEN" "$PANEL_DOMAIN" "$SETUP_TOKEN" "$C_RESET"
if [[ -n "$PANEL_ROUTED" && -z "$PHPMYADMIN_DOMAIN" ]]; then
  printf '  %s2.%s Cloudflare already sends %s to this server (route and DNS added).\n\n' "$C_CYAN" "$C_RESET" "$PANEL_DOMAIN"
else
printf '  %s2.%s In Cloudflare (Zero Trust → Networks → Tunnels → your tunnel → Routes → Add route → Published application), send\n' "$C_CYAN" "$C_RESET"
if [[ -n "$PHPMYADMIN_DOMAIN" ]]; then
  printf '     %s and %s to %shttp://localhost:80%s.\n\n' "$PANEL_DOMAIN" "$PHPMYADMIN_DOMAIN" "$C_BOLD" "$C_RESET"
else
  printf '     %s to %shttp://localhost:80%s.\n\n' "$PANEL_DOMAIN" "$C_BOLD" "$C_RESET"
fi
fi
printf '  %s3.%s Add a group and a website, and deploy it from GitHub.\n\n' "$C_CYAN" "$C_RESET"
if [[ -n "$WITH_PHPMYADMIN" ]]; then
  printf '  %sphpMyAdmin%s  %s  (user root; the password is on Server → MySQL in the panel)\n' "$C_DIM" "$C_RESET" "$PHPMYADMIN_URL"
fi
printf '  %sDatabases%s   %s\n' "$C_DIM" "$C_RESET" "${DATABASE_LABELS:+${DATABASE_LABELS}, }SQLite: each website picks one when you add it"
if [[ -n "$FREE_ADDRESS" ]]; then
  if [[ -n "${CLOUD_TELEGRAM_LINKED:-}" ]]; then
    printf '  %sTelegram%s    alerts go to your Telegram through Laravelify Cloud\n' "$C_DIM" "$C_RESET"
  else
    printf '  %sTelegram%s    link Telegram on app.laravelify.cloud → Notifications to get alerts\n' "$C_DIM" "$C_RESET"
  fi
fi
printf '  %sRedis%s       no password yet: set one in the panel under Server → Redis\n' "$C_DIM" "$C_RESET"
printf '  %sFirewall%s    open: SSH (22), HTTP (80), HTTPS (443)\n' "$C_DIM" "$C_RESET"
printf '  %sUpdates%s     sudo bash /opt/laravelify/scripts/update.sh\n' "$C_DIM" "$C_RESET"
printf '  %sDocs%s        https://docs.laravelify.cloud\n' "$C_DIM" "$C_RESET"
printf '  %sLog%s         %s\n\n' "$C_DIM" "$C_RESET" "$LOG_FILE"
