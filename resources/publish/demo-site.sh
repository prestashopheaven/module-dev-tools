#!/usr/bin/env bash
#
# NOTICE OF LICENSE
#
# This source file is subject to the Academic Free License version 3.0
# that is bundled with this package in the file LICENSE.md.
# It is also available through the world-wide-web at this URL:
# https://opensource.org/licenses/AFL-3.0
#
# @author    George van Engers | PrestaShopHeaven.com
# @copyright Since 2026 George van Engers
# @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
#
# The demo shop on the server: one PrestaShop with all modules, open for anyone to play with, reset every night.
# Lives next to its settings file demo-site.conf (written by `ps-module demo-site setup`); not part of any module.
#
#   demo-site.sh snapshot [note]       copy of everything: the files of the shop + the database -> snapshots/<time>/
#                                      (snapshots/latest points to it; the newest KEEP snapshots are kept)
#   demo-site.sh restore [<snapshot>]  put a snapshot back (default: latest): files and database, then clear the cache.
#                                      The server keeps its own app/config/parameters.php (database credentials), and
#                                      the shop domain and .htaccess follow URL: a snapshot made on another server (the
#                                      local Docker copy) works here too. The table prefix must be the same.
#   demo-site.sh activate <snapshot>   make an uploaded snapshot the latest one, restore it and test it; on a failure
#                                      the previous latest comes back (`ps-module demo-site push`)
#   demo-site.sh demo-mode on|off      PrestaShop's demo mode (_PS_MODE_DEMO_): visitors can look around the back
#                                      office but cannot upload modules or change sensitive settings
#   demo-site.sh settings              the fixed demo settings (demo-settings.php): e-mail off, the demo employee
#                                      DEMO_EMAIL / DEMO_PASSWORD, the intro page /INTRO_PATH/ with links to the shop and
#                                      the back office (login filled in) and the modules. Runs after every restore and deploy
#   demo-site.sh test                  smoke test of the shop: home page per language, TEST_PATHS, back-office login
#                                      page; HTTP 200 without PHP errors. Result in snapshots/last-test.json
#   demo-site.sh nightly               restore latest + test (the cron job)
#   demo-site.sh deploy <zip> <module> restore latest (clean shop) -> install or upgrade the module (demo-install.php)
#                                      -> test -> new snapshot. On a failure the shop goes back to the snapshot of before.
#   demo-site.sh install-cron          cron job for `nightly` at CRON_TIME (default 30 3 * * *)
#   demo-site.sh status                latest snapshot, snapshots on disk, last test
#   demo-site.sh paths                 SNAPSHOT_DIR and PS_ROOT (for `ps-module demo-site push`)
#
# Settings in demo-site.conf (shell syntax):
#   PS_ROOT=/var/www/demo            root of the PrestaShop (with app/config/parameters.php)
#   URL=https://demo.example.com     public address of the shop
#   LANGUAGES="en nl"                languages of the demo shop (iso codes in the URL); the test opens each of them
#   TEST_PATHS="/index.php?id_product=1&controller=product"   extra pages to test (optional)
#   CONNECT_TO=localhost:8090:127.0.0.1:80   curl --connect-to for the test (only when URL is not reachable from
#                                    the server itself, e.g. the local Docker copy)
#   ADMIN_DIR=admin123               back-office folder (optional; found automatically)
#   DEMO_EMAIL=demo@demo.com  DEMO_PASSWORD=demodemo   the public demo login (filled in on the login page)
#   INTRO_PATH=start                 the intro page: URL/start/
#   DOCS_URL=https://modules.example.com   docs site: the intro page links the manuals of every module
#   SNAPSHOT_DIR=/home/demo/demo-site/snapshots   (default: snapshots/ next to this script)
#   KEEP=5  PHP=php  CRON_TIME="30 3 * * *"
# Needs: bash, php, mysql + mysqldump, tar, gzip, curl; rsync when available (else the root folder is swapped).
# Commands that change the shop wait for each other (flock).

set -euo pipefail
# Cron starts with a minimal PATH (php is often in /usr/local/bin): add the usual directories.
export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONF="${DEMO_SITE_CONF:-$DIR/demo-site.conf}"
if [ -f "$CONF" ]; then
    # shellcheck source=/dev/null
    . "$CONF"
fi
PS_ROOT="${PS_ROOT:-}"
URL="${URL:-}"
LANGUAGES="${LANGUAGES:-}"
TEST_PATHS="${TEST_PATHS:-}"
ADMIN_DIR="${ADMIN_DIR:-}"
SNAPSHOT_DIR="${SNAPSHOT_DIR:-$DIR/snapshots}"
KEEP="${KEEP:-5}"
PHP="${PHP:-php}"
CRON_TIME="${CRON_TIME:-30 3 * * *}"
CONNECT_TO="${CONNECT_TO:-}"
DEMO_EMAIL="${DEMO_EMAIL:-demo@demo.com}"
DEMO_PASSWORD="${DEMO_PASSWORD:-demodemo}"
INTRO_PATH="${INTRO_PATH:-start}"
DOCS_URL="${DOCS_URL:-}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die() { log "ERROR: $*" >&2; exit 1; }

require_root() {
    [ -n "$PS_ROOT" ] || die "PS_ROOT is not set in $CONF"
    [ -f "$PS_ROOT/app/config/parameters.php" ] || die "no PrestaShop in $PS_ROOT (app/config/parameters.php missing)"
}

# Waits for other commands that change the shop (nightly, deploy, restore, snapshot).
lock() {
    if command -v flock > /dev/null 2>&1; then
        exec 9> "$DIR/.demo-site.lock"
        flock -w 3600 9 || die "another demo-site command is still running"
    fi
}

# One value of app/config/parameters.php (database_host, database_name, ...).
db_param() {
    "$PHP" -r '$p = require $argv[1]; echo (string) ($p["parameters"][$argv[2]] ?? "");' "$PS_ROOT/app/config/parameters.php" "$1"
}

# A MySQL option file with the credentials of the shop (mode 600, removed on exit): no password on the command line.
# Made in the main shell (not in $(...)), so the file lives until the script ends.
MYCNF=""
RESTORE_TMP=""
SNAPSHOT_PARTIAL=""
cleanup() {
    rm -f "$MYCNF"
    [ -z "$RESTORE_TMP" ] || rm -rf "$RESTORE_TMP"
    [ -z "$SNAPSHOT_PARTIAL" ] || rm -rf "$SNAPSHOT_PARTIAL"
}
trap cleanup EXIT
init_mycnf() {
    [ -z "$MYCNF" ] || return 0
    MYCNF="$(mktemp)"
    chmod 600 "$MYCNF"
    local host port password
    host="$(db_param database_host)"
    port="$(db_param database_port)"
    password="$(db_param database_password)"
    password="${password//\\/\\\\}"
    password="${password//\"/\\\"}"
    {
        echo "[client]"
        echo "host=${host%%:*}"
        if [ -n "$port" ]; then echo "port=$port"; elif [[ "$host" == *:* ]]; then echo "port=${host##*:}"; fi
        echo "user=$(db_param database_user)"
        echo "password=\"$password\""
    } > "$MYCNF"
}

cmd_snapshot() {
    require_root
    init_mycnf
    local note="${1:-}" stamp dest db
    stamp="$(date '+%Y%m%d-%H%M%S')"
    # Written to a .partial folder first: a failed snapshot never becomes "latest" and leaves nothing behind.
    dest="$SNAPSHOT_DIR/.partial-$stamp"
    SNAPSHOT_PARTIAL="$dest"
    db="$(db_param database_name)"
    mkdir -p "$dest"
    log "snapshot $stamp: database $db"
    mysqldump --defaults-extra-file="$MYCNF" --single-transaction --quick --routines --no-tablespaces "$db" | gzip > "$dest/db.sql.gz"
    log "snapshot $stamp: files of $PS_ROOT"
    tar -C "$PS_ROOT" --exclude='./var/cache/*' --exclude='./var/logs/*' --exclude='./install.lock' -czf "$dest/files.tar.gz" .
    printf '{"time": "%s", "note": "%s"}\n' "$(date -Iseconds)" "${note//\"/\'}" > "$dest/snapshot.json"
    mv "$dest" "$SNAPSHOT_DIR/$stamp"
    SNAPSHOT_PARTIAL=""
    dest="$SNAPSHOT_DIR/$stamp"
    ln -sfn "$stamp" "$SNAPSHOT_DIR/latest"
    # Keep the newest KEEP snapshots.
    local old
    old="$(find "$SNAPSHOT_DIR" -mindepth 1 -maxdepth 1 -type d -name '2*' | sort | head -n "-$KEEP")"
    if [ -n "$old" ]; then
        echo "$old" | xargs rm -rf
    fi
    log "snapshot $stamp done ($(du -sh "$dest" | cut -f1))${note:+: $note}"
}

cmd_restore() {
    require_root
    init_mycnf
    local name="${1:-latest}" src db tmp
    src="$SNAPSHOT_DIR/$name"
    [ -f "$src/db.sql.gz" ] && [ -f "$src/files.tar.gz" ] || die "no snapshot $name in $SNAPSHOT_DIR"
    db="$(db_param database_name)"
    log "restore $(basename "$(readlink -f "$src")"): files"
    # With rsync the files are unpacked next to this script (the parent of the shop is often not writable for the
    # account); without rsync the folders are swapped, which needs the same file system as the shop.
    if command -v rsync > /dev/null 2>&1; then
        RESTORE_TMP="$DIR/.restore-$$"
    else
        RESTORE_TMP="$(dirname "$PS_ROOT")/.$(basename "$PS_ROOT").restore-$$"
    fi
    tmp="$RESTORE_TMP"
    mkdir -p "$tmp" || die "cannot create $tmp"
    tar --warning=no-timestamp -C "$tmp" -xzf "$src/files.tar.gz"
    # This server's own database settings stay: the snapshot can come from another server.
    cp -p "$PS_ROOT/app/config/parameters.php" "$tmp/app/config/parameters.php"
    if command -v rsync > /dev/null 2>&1; then
        # Files, links, permissions and times; not owner and group (an SSH account cannot set those).
        rsync -rlpt --delete --exclude='/var/cache/' --exclude='/var/logs/' "$tmp/" "$PS_ROOT/"
        rm -rf "$tmp"
        RESTORE_TMP=""
    else
        local old
        old="$(dirname "$PS_ROOT")/.$(basename "$PS_ROOT").old-$$"
        mv "$PS_ROOT" "$old"
        mv "$tmp" "$PS_ROOT"
        rm -rf "$old"
    fi
    log "restore: database $db"
    # Drop every table first: tables made after the snapshot must go too.
    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        mysql --defaults-extra-file="$MYCNF" -N -B "$db" -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = DATABASE()"
    } | mysql --defaults-extra-file="$MYCNF" "$db"
    gunzip -c "$src/db.sql.gz" | mysql --defaults-extra-file="$MYCNF" "$db"
    fix_domain
    rm -rf "$PS_ROOT/var/cache/"*
    cmd_settings
    warm_cache
    log "restore done"
}

# Builds the Symfony cache, so the first visitor after a reset does not wait for it.
warm_cache() {
    (cd "$PS_ROOT" && "$PHP" -d memory_limit=2048M bin/console cache:warmup --env=prod --no-debug > /dev/null 2>&1) || log "warning: cache:warmup failed (the first visit builds the cache)"
}

# The fixed demo settings: e-mail off, the demo employee, the intro page (demo-settings.php next to this script).
cmd_settings() {
    require_root
    local reset minute hour
    read -r minute hour _ <<< "$CRON_TIME"
    # "30 3 * * *" -> 03:30 for the intro page; anything else (*/5, lists) is shown as the cron expression.
    if [[ "$hour" =~ ^[0-9]+$ && "$minute" =~ ^[0-9]+$ ]]; then
        reset="$(printf '%02d:%02d' "$((10#$hour))" "$((10#$minute))")"
    else
        reset="$CRON_TIME (cron)"
    fi
    (cd "$PS_ROOT" && "$PHP" -d memory_limit=2048M "$DIR/demo-settings.php" "$URL" "$DEMO_EMAIL" "$DEMO_PASSWORD" "$INTRO_PATH" "$DOCS_URL" "$reset" "$LANGUAGES") \
        || die "demo-settings.php failed"
}

# After a restore: the shop answers on the domain of URL (the snapshot may come from another server), with a fresh
# .htaccess. Fails when the snapshot uses another table prefix than this server's parameters.php.
fix_domain() {
    init_mycnf
    local prefix db host path ssl
    prefix="$(db_param database_prefix)"
    db="$(db_param database_name)"
    if [ -z "$(mysql --defaults-extra-file="$MYCNF" -N -B "$db" -e "SHOW TABLES LIKE '${prefix}configuration'")" ]; then
        die "the snapshot has no ${prefix}configuration table: its table prefix differs from database_prefix '$prefix' in parameters.php"
    fi
    [ -n "$URL" ] || return 0
    local rest="${URL#*://}"
    host="${rest%%/*}"
    path="/"
    if [[ "$rest" == */* ]]; then
        path="/${rest#*/}"
        path="${path%/}/"
    fi
    ssl=0
    if [[ "$URL" == https://* ]]; then ssl=1; fi
    mysql --defaults-extra-file="$MYCNF" "$db" -e "
        UPDATE ${prefix}shop_url SET domain = '$host', domain_ssl = '$host', physical_uri = '$path' WHERE main = 1;
        UPDATE ${prefix}configuration SET value = '$host' WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL');
        UPDATE ${prefix}configuration SET value = '$ssl' WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE');"
    (cd "$PS_ROOT" && "$PHP" -r 'require "config/config.inc.php"; exit(Tools::generateHtaccess() ? 0 : 1);') \
        || log "warning: .htaccess could not be regenerated"
    log "domain: $host$path (ssl $ssl)"
}

# Prints the back-office folder (ADMIN_DIR, else the folder in PS_ROOT with an index.php and a filemanager or themes).
admin_dir() {
    if [ -n "$ADMIN_DIR" ]; then echo "$ADMIN_DIR"; return; fi
    local d
    for d in "$PS_ROOT"/*/; do
        d="${d%/}"
        if [ -f "$d/index.php" ] && [ -d "$d/themes" ] && [ -f "$d/init.php" ]; then basename "$d"; return; fi
    done
}

cmd_test() {
    [ -n "$URL" ] || die "URL is not set in $CONF"
    local base="${URL%/}" pages=() failures=() page body code admin iso path count=0
    pages+=("$base/")
    set -f
    # shellcheck disable=SC2206
    local langs=($LANGUAGES) extra=($TEST_PATHS)
    set +f
    if [ "${#langs[@]}" -gt 1 ]; then
        for iso in "${langs[@]}"; do pages+=("$base/$iso/"); done
    fi
    for path in "${extra[@]}"; do pages+=("$base$path"); done
    pages+=("$base/$INTRO_PATH/")
    if [ -n "$PS_ROOT" ]; then
        admin="$(admin_dir || true)"
        if [ -n "$admin" ]; then pages+=("$base/$admin/index.php?controller=AdminLogin&email=${DEMO_EMAIL/@/%40}&password=$DEMO_PASSWORD"); fi
    fi
    body="$(mktemp)"
    for page in "${pages[@]}"; do
        count=$((count + 1))
        code="$(curl -s -L --max-time 180 ${CONNECT_TO:+--connect-to "$CONNECT_TO"} -o "$body" -w '%{http_code}' "$page" || echo 000)"
        if [ "$code" != "200" ]; then
            failures+=("$page: HTTP $code")
        elif grep -Eq 'Fatal error|Parse error|Uncaught |Whoops, looks like something went wrong|\[PrestaShopException\]' "$body"; then
            failures+=("$page: PHP error in the page")
        elif [[ "$page" == *controller=AdminLogin* ]] && ! grep -q "value=\"$DEMO_EMAIL\"" "$body"; then
            failures+=("$page: the demo login is not filled in (demo mode off?)")
        fi
    done
    rm -f "$body"
    mkdir -p "$SNAPSHOT_DIR"
    local json="" f
    for f in "${failures[@]+"${failures[@]}"}"; do json="$json${json:+, }\"${f//\"/\'}\""; done
    printf '{"time": "%s", "ok": %s, "pages": %d, "failures": [%s]}\n' "$(date -Iseconds)" \
        "$([ "${#failures[@]}" -eq 0 ] && echo true || echo false)" "$count" "$json" > "$SNAPSHOT_DIR/last-test.json"
    if [ "${#failures[@]}" -gt 0 ]; then
        for f in "${failures[@]}"; do log "test FAILED: $f"; done
        return 1
    fi
    log "test ok: $count page(s)"
}

cmd_deploy() {
    require_root
    local zip="${1:-}" module="${2:-}" before output version
    [ -f "$zip" ] && [[ "$module" =~ ^[a-z][a-z0-9]{3,}$ ]] || die "usage: demo-site.sh deploy <zip> <module>"
    if [ ! -e "$SNAPSHOT_DIR/latest" ]; then
        cmd_snapshot "before the first deploy"
    fi
    before="$(basename "$(readlink -f "$SNAPSHOT_DIR/latest")")"
    log "deploy $module: start from the clean shop of snapshot $before"
    cmd_restore latest
    if ! output="$(cd "$PS_ROOT" && "$PHP" "$DIR/demo-install.php" "$zip" "$module" "$PHP" 2>&1)"; then
        echo "$output"
        log "deploy $module: install failed, back to snapshot $before"
        cmd_restore "$before"
        exit 1
    fi
    echo "$output"
    version="$(echo "$output" | sed -n 's/^DEMO-OK //p' | tail -n1)"
    cmd_settings
    if ! cmd_test; then
        log "deploy $module: the shop fails its test after the install, back to snapshot $before"
        cmd_restore "$before"
        exit 1
    fi
    cmd_snapshot "deploy $module $version"
    echo "DEMO-OK $version"
}

cmd_activate() {
    require_root
    local name="${1:-}" before
    [ -n "$name" ] && [ -f "$SNAPSHOT_DIR/$name/db.sql.gz" ] && [ -f "$SNAPSHOT_DIR/$name/files.tar.gz" ] || die "usage: demo-site.sh activate <snapshot in $SNAPSHOT_DIR>"
    before="$(basename "$(readlink -f "$SNAPSHOT_DIR/latest" 2> /dev/null || echo none)")"
    log "activate $name (was: $before)"
    cmd_restore "$name"
    if ! cmd_test; then
        if [ "$before" != "none" ] && [ -d "$SNAPSHOT_DIR/$before" ]; then
            log "activate $name: the shop fails its test, back to $before"
            cmd_restore "$before"
        fi
        exit 1
    fi
    ln -sfn "$name" "$SNAPSHOT_DIR/latest"
    log "activate $name done: it is the latest snapshot now"
}

cmd_demo_mode() {
    require_root
    local value file="$PS_ROOT/config/defines.inc.php"
    case "${1:-}" in
        on) value=true ;;
        off) value=false ;;
        *) die "usage: demo-site.sh demo-mode on|off" ;;
    esac
    grep -q "_PS_MODE_DEMO_" "$file" || die "no _PS_MODE_DEMO_ in $file"
    sed -i -E "s/define\('_PS_MODE_DEMO_', *(true|false)\);/define('_PS_MODE_DEMO_', $value);/" "$file"
    grep -q "define('_PS_MODE_DEMO_', $value);" "$file" || die "could not set _PS_MODE_DEMO_ in $file"
    rm -rf "$PS_ROOT/var/cache/"*
    log "demo mode $1"
}

cmd_install_cron() {
    command -v crontab > /dev/null 2>&1 || die "crontab is not available"
    # The PATH of this session goes along: cron's own PATH misses where php and mysql often live.
    local line="$CRON_TIME PATH='$PATH' $DIR/demo-site.sh nightly >> $DIR/nightly.log 2>&1"
    { crontab -l 2> /dev/null | grep -v 'demo-site.sh nightly' || true; echo "$line"; } | crontab -
    log "cron: $line"
}

cmd_status() {
    if [ -e "$SNAPSHOT_DIR/latest" ]; then
        echo "latest snapshot: $(basename "$(readlink -f "$SNAPSHOT_DIR/latest")") $(cat "$SNAPSHOT_DIR/latest/snapshot.json" 2> /dev/null || true)"
    else
        echo "latest snapshot: none"
    fi
    echo "snapshots: $(find "$SNAPSHOT_DIR" -mindepth 1 -maxdepth 1 -type d -name '2*' 2> /dev/null | wc -l | tr -d ' ') (keep $KEEP)"
    echo "last test: $(cat "$SNAPSHOT_DIR/last-test.json" 2> /dev/null || echo none)"
    echo "cron: $(crontab -l 2> /dev/null | grep 'demo-site.sh nightly' || echo none)"
}

command="${1:-help}"
shift || true
case "$command" in
    snapshot) lock; cmd_snapshot "$@" ;;
    restore) lock; cmd_restore "$@" ;;
    test) cmd_test ;;
    nightly) lock; log "nightly reset"; cmd_restore latest; cmd_test ;;
    deploy) lock; cmd_deploy "$@" ;;
    activate) lock; cmd_activate "$@" ;;
    demo-mode) lock; cmd_demo_mode "$@" ;;
    settings) lock; cmd_settings ;;
    install-cron) cmd_install_cron ;;
    status) cmd_status ;;
    paths) echo "SNAPSHOT_DIR=$SNAPSHOT_DIR"; echo "PS_ROOT=$PS_ROOT" ;;
    *) sed -n '2,/^set -euo/p' "$0" | sed -e '/^set -euo/d' -e 's/^# \{0,1\}//'; [ "$command" = help ] ;;
esac
