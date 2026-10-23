#!/usr/bin/env bash
#
# NOTICE OF LICENSE
#
# This source file is subject to the Academic Free License version 3.0
# that is bundled with this package in the file LICENSE.md.
# It is also available through the world-wide-web at this URL:
# https://opensource.org/licenses/AFL-3.0
#
# @author    George van Engers | PrestaHaven.com
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
#                                      the back office (login filled in) and the modules. Runs after every restore; a deploy
#                                      only rewrites the intro page
#   demo-site.sh settings intro        only the intro page (its list of modules); the rest of the shop stays as it is
#   demo-site.sh test                 smoke test of the shop: home page per language, TEST_PATHS, back-office login
#                                      page; HTTP 200 without PHP errors. Result in snapshots/last-test.json
#   demo-site.sh nightly               restore latest + test (the cron job)
#   demo-site.sh deploy <zip> <module> install or upgrade one module in the shop as it is (demo-install.php; no restore,
#                                      the other files and settings stay) -> intro page -> test -> new snapshot. The
#                                      test runs before the install too: only pages that broke since then count. On a
#                                      failure only the module folder and the database go back to how they were.
#   demo-site.sh apply <dir> [note]    runs <dir>/apply.php in the shop as it is (e.g. the test data of all modules,
#                                      `ps-module demo-site apply`) -> intro page -> test -> new snapshot. apply.php must
#                                      print APPLY-OK. Like deploy: the test runs before it too, and on a failure the
#                                      database goes back to how it was (files it wrote stay until the next restore)
#   demo-site.sh install-cron          cron job for `nightly` at CRON_TIME (default 30 3 * * *)
#   demo-site.sh status                latest snapshot, snapshots on disk, last test
#   demo-site.sh paths                 SNAPSHOT_DIR and PS_ROOT (for `ps-module demo-site push`)
#
#   -v / --verbose (before the command) or VERBOSE=1: report every step (settings, sizes, times, each tested page,
#   rsync and cache output). `ps-module -v demo-site ...` and `ps-module -v publish demo` set it on the server.
#   Database names, users and passwords are never printed.
#
# Settings in demo-site.conf (shell syntax):
#   PS_ROOT=/var/www/demo            root of the PrestaShop (with app/config/parameters.php)
#   URL=https://demo.example.com     public address of the shop
#   LANGUAGES="en nl"                languages of the demo shop (iso codes in the URL); the test opens each of them
#   TEST_PATHS="/index.php?id_product=1&controller=product"   extra pages to test (optional)
#   CONNECT_TO=localhost:8090:127.0.0.1:80   curl --connect-to for the test (only when URL is not reachable from
#                                    the server itself, e.g. the local Docker copy)
#                                    Empty: the test finds a route itself (direct, 127.0.0.1, the server's own
#                                    addresses: control panels let the web server listen on the public address only)
#   KEEP_PATHS=".well-known"         folders in PS_ROOT that are not part of the demo: never in a snapshot, never
#                                    removed by a restore. Folders with a .docs-site file (the docs site of a module,
#                                    ps-module publish docs) and .well-known are always kept.
#   CRON_INSTALL=no                  do not put the nightly job in crontab (schedule it in the control panel)
#   ADMIN_DIR=admin123               back-office folder (optional; found automatically)
#   DEMO_EMAIL=demo@demo.com  DEMO_PASSWORD=demodemo   the public demo login (filled in on the login page)
#   INTRO_PATH=start                 the intro page: URL/start/
#   DOCS_URL=https://modules.example.com   docs site: the intro page links <DOCS_URL>/<module>/index.php of every
#                                    module (empty: the shop's own domain, for modules with <module>/index.php in the root)
#   SNAPSHOT_DIR=/home/demo/demo-site/snapshots   (default: snapshots/ next to this script)
#   KEEP=5  PHP=php  CRON_TIME="30 3 * * *"
# Needs: bash, php, mysql + mysqldump, tar, gzip, curl; rsync when available (else the root folder is swapped).
# Commands that change the shop wait for each other (flock).

set -euo pipefail
# Cron starts with a minimal PATH (php is often in /usr/local/bin): add the usual directories.
export PATH="$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"

VERBOSE="${VERBOSE:-0}"
case "${1:-}" in -v | --verbose) VERBOSE=1; shift ;; esac
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
KEEP_PATHS="${KEEP_PATHS:-}"
CRON_INSTALL="${CRON_INSTALL:-yes}"
DEMO_EMAIL="${DEMO_EMAIL:-demo@demo.com}"
DEMO_PASSWORD="${DEMO_PASSWORD:-demodemo}"
# MariaDB 11 calls its clients mariadb and mariadb-dump (the mysql names print a deprecation warning).
MYSQL="$(command -v mariadb || echo mysql)"
MYSQLDUMP="$(command -v mariadb-dump || echo mysqldump)"
INTRO_PATH="${INTRO_PATH:-start}"
DOCS_URL="${DOCS_URL:-}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }
die() { log "ERROR: $*" >&2; exit 1; }
# Verbose only (VERBOSE=1): one more line about what happens.
vlog() { if [ "$VERBOSE" = 1 ]; then log "  [v] $*"; fi; }
# The size of a file or folder (for verbose lines).
vsize() { du -sh "$1" 2> /dev/null | cut -f1; }

require_root() {
    [ -n "$PS_ROOT" ] || die "PS_ROOT is not set in $CONF"
    [ -f "$PS_ROOT/app/config/parameters.php" ] || die "no PrestaShop in $PS_ROOT (app/config/parameters.php missing)"
}

# Waits for other commands that change the shop (nightly, deploy, restore, snapshot).
lock() {
    if command -v flock > /dev/null 2>&1; then
        exec 9> "$DIR/.demo-site.lock"
        if ! flock -n 9; then
            vlog "waiting for another demo-site command (lock $DIR/.demo-site.lock)"
            flock -w 3600 9 || die "another demo-site command is still running"
        fi
        vlog "lock taken"
    else
        vlog "no flock on this server: commands do not wait for each other"
    fi
}

# Verbose only: the settings this run uses (no database values).
vsettings() {
    [ "$VERBOSE" = 1 ] || return 0
    vlog "settings: $CONF ($([ -f "$CONF" ] && echo found || echo missing))"
    vlog "  PS_ROOT=$PS_ROOT  URL=$URL  LANGUAGES=$LANGUAGES  ADMIN_DIR=${ADMIN_DIR:-(found automatically)}"
    vlog "  SNAPSHOT_DIR=$SNAPSHOT_DIR  KEEP=$KEEP  CRON_TIME=$CRON_TIME  CRON_INSTALL=$CRON_INSTALL"
    vlog "  CONNECT_TO=${CONNECT_TO:-(found per test)}  KEEP_PATHS=${KEEP_PATHS:-(none)}  INTRO_PATH=$INTRO_PATH  TEST_PATHS=${TEST_PATHS:-(none)}"
    vlog "  PHP=$PHP ($("$PHP" -r 'echo PHP_VERSION;' 2> /dev/null || echo 'not found'))  MYSQL=$MYSQL  MYSQLDUMP=$MYSQLDUMP  rsync: $(command -v rsync > /dev/null 2>&1 && echo yes || echo no)"
    vlog "  free disk: snapshots $(df -h "$SNAPSHOT_DIR" 2> /dev/null | awk 'NR==2 {print $4}'), shop $(df -h "${PS_ROOT:-.}" 2> /dev/null | awk 'NR==2 {print $4}')"
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
DEPLOY_BACKUP=""
# The failures of the last cmd_test, one "<page>: <reason>" per item (deploy compares before and after).
TEST_FAILURES=()
cleanup() {
    rm -f "$MYCNF"
    [ -z "$RESTORE_TMP" ] || rm -rf "$RESTORE_TMP"
    [ -z "$SNAPSHOT_PARTIAL" ] || rm -rf "$SNAPSHOT_PARTIAL"
    [ -z "$DEPLOY_BACKUP" ] || rm -rf "$DEPLOY_BACKUP"
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

# The top-level folders of PS_ROOT that do not belong to the demo (see KEEP_PATHS), one per line.
keep_paths() {
    local p
    {
        echo ".well-known"
        set -f
        for p in $KEEP_PATHS; do echo "${p%/}"; done
        set +f
        find "$PS_ROOT" -mindepth 2 -maxdepth 2 -name .docs-site -printf '%h\n' 2> /dev/null | xargs -r -n1 basename
    } | grep -v '^$' | sort -u
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
    log "snapshot $stamp: database"
    local began=$SECONDS
    "$MYSQLDUMP" --defaults-extra-file="$MYCNF" --single-transaction --quick --routines --no-tablespaces "$db" | gzip > "$dest/db.sql.gz"
    vlog "database dump: $(vsize "$dest/db.sql.gz") in $((SECONDS - began))s"
    log "snapshot $stamp: files of $PS_ROOT"
    local excludes=() p kept=()
    while IFS= read -r p; do excludes+=("--exclude=./$p"); kept+=("$p"); done < <(keep_paths)
    vlog "left out of the snapshot: var/cache, var/logs, install.lock, ${kept[*]:-}"
    began=$SECONDS
    tar -C "$PS_ROOT" --exclude='./var/cache/*' --exclude='./var/logs/*' --exclude='./install.lock' "${excludes[@]}" -czf "$dest/files.tar.gz" .
    vlog "files: $(vsize "$dest/files.tar.gz") in $((SECONDS - began))s"
    printf '{"time": "%s", "note": "%s"}\n' "$(date -Iseconds)" "${note//\"/\'}" > "$dest/snapshot.json"
    mv "$dest" "$SNAPSHOT_DIR/$stamp"
    SNAPSHOT_PARTIAL=""
    dest="$SNAPSHOT_DIR/$stamp"
    ln -sfn "$stamp" "$SNAPSHOT_DIR/latest"
    # Keep the newest KEEP snapshots.
    local old
    old="$(find "$SNAPSHOT_DIR" -mindepth 1 -maxdepth 1 -type d -name '2*' | sort | head -n "-$KEEP")"
    if [ -n "$old" ]; then
        vlog "removing old snapshots (keep $KEEP): $(echo "$old" | xargs -n1 basename | tr '\n' ' ')"
        echo "$old" | xargs rm -rf
    fi
    log "snapshot $stamp done ($(du -sh "$dest" | cut -f1))${note:+: $note}"
}

cmd_restore() {
    require_root
    init_mycnf
    local name="${1:-latest}" src tmp p keep=()
    src="$SNAPSHOT_DIR/$name"
    [ -f "$src/db.sql.gz" ] && [ -f "$src/files.tar.gz" ] || die "no snapshot $name in $SNAPSHOT_DIR"
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
    vlog "snapshot folder: $(readlink -f "$src") (files $(vsize "$src/files.tar.gz"), database $(vsize "$src/db.sql.gz")); unpacking into $tmp"
    local began=$SECONDS
    tar --warning=no-timestamp -C "$tmp" -xzf "$src/files.tar.gz"
    vlog "unpacked in $((SECONDS - began))s"
    # This server's own database settings stay: the snapshot can come from another server.
    cp -p "$PS_ROOT/app/config/parameters.php" "$tmp/app/config/parameters.php"
    # Folders that are not part of the demo (docs site, .well-known) stay as they are.
    while IFS= read -r p; do keep+=("$p"); done < <(keep_paths)
    vlog "kept as they are: ${keep[*]:-}, var/cache, var/logs, app/config/parameters.php"
    if command -v rsync > /dev/null 2>&1; then
        local excludes=() stats=()
        for p in "${keep[@]+"${keep[@]}"}"; do excludes+=("--exclude=/$p"); done
        if [ "$VERBOSE" = 1 ]; then stats=(--stats); fi
        began=$SECONDS
        # Files, links, permissions and times; not owner and group (an SSH account cannot set those).
        rsync -rlpt --delete "${stats[@]+"${stats[@]}"}" --exclude='/var/cache/' --exclude='/var/logs/' "${excludes[@]+"${excludes[@]}"}" "$tmp/" "$PS_ROOT/"
        vlog "rsync done in $((SECONDS - began))s"
        rm -rf "$tmp"
        RESTORE_TMP=""
    else
        local old
        old="$(dirname "$PS_ROOT")/.$(basename "$PS_ROOT").old-$$"
        for p in "${keep[@]+"${keep[@]}"}"; do
            if [ -e "$PS_ROOT/$p" ]; then rm -rf "${tmp:?}/$p"; mv "$PS_ROOT/$p" "$tmp/$p"; fi
        done
        vlog "no rsync: swapping the shop folder (the old one goes to $old and is removed)"
        mv "$PS_ROOT" "$old"
        mv "$tmp" "$PS_ROOT"
        rm -rf "$old"
    fi
    log "restore: database"
    db_import "$src/db.sql.gz"
    fix_domain
    clear_cache
    cmd_settings
    warm_cache
    log "restore done"
}

# Replaces the whole shop database with a dump (db.sql.gz): every table is dropped first, so tables made after the
# dump go too.
db_import() {
    init_mycnf
    local dump="$1" db began=$SECONDS
    db="$(db_param database_name)"
    vlog "tables before: $(count_tables "$db") (all dropped first)"
    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        "$MYSQL" --defaults-extra-file="$MYCNF" -N -B "$db" -e "SELECT CONCAT('DROP TABLE IF EXISTS \`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = DATABASE()"
    } | "$MYSQL" --defaults-extra-file="$MYCNF" "$db"
    gunzip -c "$dump" | "$MYSQL" --defaults-extra-file="$MYCNF" "$db"
    vlog "database imported in $((SECONDS - began))s: $(count_tables "$db") tables"
}

# Verbose only: the number of tables in the shop database.
count_tables() {
    [ "$VERBOSE" = 1 ] || return 0
    "$MYSQL" --defaults-extra-file="$MYCNF" -N -B "$1" -e 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()' 2> /dev/null || echo '?'
}

# Empties var/cache. Visitors can write to it at the same moment (a plain rm -rf then fails with "Directory not
# empty"), so the folder is first moved aside in one step and then removed. When it cannot be moved (a mount point,
# e.g. the Docker volume of the local copy: "Device or resource busy"), it is emptied in place, a few tries.
clear_cache() {
    local old="$PS_ROOT/var/.cache-old-$$" try
    if [ -d "$PS_ROOT/var/cache" ] && mv "$PS_ROOT/var/cache" "$old" 2> /dev/null; then
        mkdir -p "$PS_ROOT/var/cache"
        rm -rf "$old" 2> /dev/null || rm -rf "$old" 2> /dev/null || log "warning: could not remove $old completely"
        vlog "cache cleared (var/cache moved aside and removed)"
        return 0
    fi
    mkdir -p "$PS_ROOT/var/cache"
    for try in 1 2 3; do
        if find "$PS_ROOT/var/cache" -mindepth 1 -delete 2> /dev/null; then
            vlog "cache cleared (var/cache emptied in place, try $try)"
            return 0
        fi
        sleep 2
    done
    log "warning: var/cache could not be emptied completely (files in use); PrestaShop rebuilds what it needs"
}

# Builds the Symfony cache, so the first visitor after a reset does not wait for it.
warm_cache() {
    local began=$SECONDS
    if [ "$VERBOSE" = 1 ]; then
        vlog "\$ $PHP bin/console cache:warmup --env=prod --no-debug   (in PS_ROOT)"
        (cd "$PS_ROOT" && "$PHP" -d memory_limit=2048M bin/console cache:warmup --env=prod --no-debug 2>&1) || log "warning: cache:warmup failed (the first visit builds the cache)"
        vlog "cache warmed in $((SECONDS - began))s"
    else
        (cd "$PS_ROOT" && "$PHP" -d memory_limit=2048M bin/console cache:warmup --env=prod --no-debug > /dev/null 2>&1) || log "warning: cache:warmup failed (the first visit builds the cache)"
    fi
}

# The fixed demo settings: e-mail off, the demo employee, the intro page (demo-settings.php next to this script).
# `cmd_settings intro`: only the intro page (its list of modules), the rest of the shop stays as it is (deploy).
cmd_settings() {
    require_root
    local only="${1:-}" reset minute hour
    read -r minute hour _ <<< "$CRON_TIME"
    # "30 3 * * *" -> 03:30 for the intro page; anything else (*/5, lists) is shown as the cron expression.
    if [[ "$hour" =~ ^[0-9]+$ && "$minute" =~ ^[0-9]+$ ]]; then
        reset="$(printf '%02d:%02d' "$((10#$hour))" "$((10#$minute))")"
    else
        reset="$CRON_TIME (cron)"
    fi
    vlog "demo settings: demo-settings.php (URL $URL, login $DEMO_EMAIL, intro /$INTRO_PATH/, reset shown as $reset, languages $LANGUAGES)"
    (cd "$PS_ROOT" && VERBOSE="$VERBOSE" DEMO_SETTINGS_ONLY="$only" "$PHP" -d memory_limit=2048M "$DIR/demo-settings.php" "$URL" "$DEMO_EMAIL" "$DEMO_PASSWORD" "$INTRO_PATH" "$DOCS_URL" "$reset" "$LANGUAGES") \
        || die "demo-settings.php failed"
}

# After a restore: the shop answers on the domain of URL (the snapshot may come from another server), with a fresh
# .htaccess. Fails when the snapshot uses another table prefix than this server's parameters.php.
fix_domain() {
    init_mycnf
    local prefix db host path ssl
    prefix="$(db_param database_prefix)"
    db="$(db_param database_name)"
    if [ -z "$("$MYSQL" --defaults-extra-file="$MYCNF" -N -B "$db" -e "SHOW TABLES LIKE '${prefix}configuration'")" ]; then
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
    "$MYSQL" --defaults-extra-file="$MYCNF" "$db" -e "
        UPDATE ${prefix}shop_url SET domain = '$host', domain_ssl = '$host', physical_uri = '$path' WHERE main = 1;
        UPDATE ${prefix}configuration SET value = '$host' WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL');
        UPDATE ${prefix}configuration SET value = '$ssl' WHERE name IN ('PS_SSL_ENABLED', 'PS_SSL_ENABLED_EVERYWHERE');"
    vlog "shop_url and PS_SHOP_DOMAIN(_SSL) set to $host$path, PS_SSL_ENABLED(_EVERYWHERE) $ssl (table prefix $prefix)"
    (cd "$PS_ROOT" && "$PHP" -r 'require "config/config.inc.php"; exit(Tools::generateHtaccess() ? 0 : 1);') \
        || log "warning: .htaccess could not be regenerated"
    vlog ".htaccess regenerated"
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

# Without CONNECT_TO: test the shop on this server itself, through 127.0.0.1 or one of its own addresses (SNI and Host
# stay the shop's domain), probed with a static file (robots.txt: right after a cache clear the home page can take longer
# than the probe waits). The public route can pass a CDN or proxy (Cloudflare) that the server reaches badly or not
# at all; a control panel often lets the web server listen on the public address only. No local route: the public one.
find_route() {
    [ -z "$CONNECT_TO" ] || return 0
    local url="$1" host port address target
    host="$(echo "$url" | sed -E 's#^https?://([^/:]+).*#\1#')"
    port=443
    case "$url" in http://*) port=80 ;; esac
    for address in 127.0.0.1 $(hostname -I 2> /dev/null); do
        target="$address"
        case "$address" in *:*) target="[$address]" ;; esac
        local tried
        tried="$(curl -s -o /dev/null -m 30 -w '%{http_code}' --connect-to "$host:$port:$target:$port" "$url" 2> /dev/null || true)"
        vlog "route via $target:$port: HTTP ${tried:-000}"
        if [ "${tried:-000}" != "000" ]; then
            CONNECT_TO="$host:$port:$target:$port"
            log "test: via this server's own address (--connect-to $CONNECT_TO)"
            return 0
        fi
    done
    vlog "no route via this server's own addresses: testing over the public address"
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
    find_route "$base/robots.txt"
    body="$(mktemp)"
    for page in "${pages[@]}"; do
        count=$((count + 1))
        local began=$SECONDS
        code="$(curl -s -L --max-time 180 ${CONNECT_TO:+--connect-to "$CONNECT_TO"} -o "$body" -w '%{http_code}' "$page" 2> /dev/null || true)"
        code="${code:-000}"
        vlog "test $page: HTTP $code, $(wc -c < "$body" | tr -d ' ') bytes, $((SECONDS - began))s"
        if [ "$code" != "200" ]; then
            failures+=("$page: HTTP $code")
        elif grep -Eq 'Fatal error|Parse error|Uncaught |Whoops, looks like something went wrong|\[PrestaShopException\]' "$body"; then
            failures+=("$page: PHP error in the page")
        elif [[ "$page" == *controller=AdminLogin* ]] && ! grep -q "value=\"$DEMO_EMAIL\"" "$body"; then
            failures+=("$page: the demo login is not filled in (demo mode off?)")
        fi
    done
    rm -f "$body"
    TEST_FAILURES=("${failures[@]+"${failures[@]}"}")
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

# Installs or upgrades one module in the shop as it is now: only modules/<module> and what the module's own install or
# upgrade changes in the database. The other files, the other modules and the settings stay as they are (no restore).
# Before the install the module folder and the database are copied aside; on a failed install or test they come back.
# Afterwards a new snapshot, so the nightly reset keeps the module (the snapshot holds the shop as it is at that moment).
cmd_deploy() {
    require_root
    init_mycnf
    local zip="${1:-}" module="${2:-}" output version backup db began f new=() before=()
    [ -f "$zip" ] && [[ "$module" =~ ^[a-z][a-z0-9]{3,}$ ]] || die "usage: demo-site.sh deploy <zip> <module>"
    # The installer runs in the shop folder: a relative path would point nowhere there.
    zip="$(readlink -f "$zip")"
    log "deploy $module: install or upgrade in the shop as it is (the rest of the shop is not touched)"
    vlog "zip: $zip ($(vsize "$zip"))"
    backup="$DIR/.deploy-$$"
    DEPLOY_BACKUP="$backup"
    mkdir -p "$backup"
    db="$(db_param database_name)"
    began=$SECONDS
    "$MYSQLDUMP" --defaults-extra-file="$MYCNF" --single-transaction --quick --routines --no-tablespaces "$db" | gzip > "$backup/db.sql.gz"
    if [ -d "$PS_ROOT/modules/$module" ]; then
        cp -a "$PS_ROOT/modules/$module" "$backup/module"
    fi
    vlog "copied aside for a rollback: database ($(vsize "$backup/db.sql.gz")), modules/$module ($([ -d "$backup/module" ] && vsize "$backup/module" || echo 'not there yet')) in $((SECONDS - began))s"
    # The shop is not reset first, so it can have problems of its own (settings changed by hand): the test after the
    # install only counts pages that passed before it.
    log "test before the install (problems that are already there do not stop the deploy)"
    cmd_test || true
    before=("${TEST_FAILURES[@]+"${TEST_FAILURES[@]}"}")
    vlog "\$ $PHP demo-install.php $zip $module   (in PS_ROOT)"
    if ! output="$(cd "$PS_ROOT" && VERBOSE="$VERBOSE" "$PHP" "$DIR/demo-install.php" "$zip" "$module" "$PHP" 2>&1)"; then
        echo "$output"
        deploy_rollback "$module" "$backup" "the install failed"
        exit 1
    fi
    echo "$output"
    version="$(echo "$output" | sed -n 's/^DEMO-OK //p' | tail -n1)"
    cmd_settings intro
    warm_cache
    if ! cmd_test; then
        for f in "${TEST_FAILURES[@]}"; do
            if ! printf '%s\n' "${before[@]+"${before[@]}"}" | grep -Fxq -- "$f"; then new+=("$f"); fi
        done
        if [ "${#new[@]}" -gt 0 ]; then
            for f in "${new[@]}"; do log "new since the install: $f"; done
            deploy_rollback "$module" "$backup" "the shop fails its test after the install"
            exit 1
        fi
        log "test: the same problems as before the install, none new (not caused by $module): the deploy stands"
    fi
    cmd_snapshot "deploy $module $version"
    echo "DEMO-OK $version"
}

# Runs <dir>/apply.php (PHP CLI, in PS_ROOT, as this user) in the shop as it is: data for the demo that is made with
# PrestaShop itself, e.g. the test data of every module (`ps-module demo-site apply`). The same safety net as deploy.
# apply.php gets the public address and the back-office folder in DEMO_SITE_URL / DEMO_SITE_ADMIN_DIR.
cmd_apply() {
    require_root
    init_mycnf
    local dir="${1:-}" note="${2:-apply}" output backup db began f new=() before=()
    [ -f "$dir/apply.php" ] || die "usage: demo-site.sh apply <dir with apply.php> [note]"
    dir="$(readlink -f "$dir")"
    log "apply $note: $dir/apply.php in the shop as it is"
    backup="$DIR/.apply-$$"
    DEPLOY_BACKUP="$backup"
    mkdir -p "$backup"
    db="$(db_param database_name)"
    began=$SECONDS
    "$MYSQLDUMP" --defaults-extra-file="$MYCNF" --single-transaction --quick --routines --no-tablespaces "$db" | gzip > "$backup/db.sql.gz"
    vlog "database copied aside for a rollback ($(vsize "$backup/db.sql.gz")) in $((SECONDS - began))s"
    log "test before apply.php (problems that are already there do not stop it)"
    cmd_test || true
    before=("${TEST_FAILURES[@]+"${TEST_FAILURES[@]}"}")
    vlog "\$ $PHP $dir/apply.php   (in PS_ROOT)"
    if ! output="$(cd "$PS_ROOT" && VERBOSE="$VERBOSE" DEMO_SITE_URL="$URL" DEMO_SITE_ADMIN_DIR="$(admin_dir)" "$PHP" -d memory_limit=2048M "$dir/apply.php" 2>&1)" \
        || ! printf '%s\n' "$output" | grep -q '^APPLY-OK'; then
        echo "$output"
        apply_rollback "$backup" "apply.php failed"
        exit 1
    fi
    echo "$output"
    clear_cache
    cmd_settings intro
    warm_cache
    if ! cmd_test; then
        for f in "${TEST_FAILURES[@]}"; do
            if ! printf '%s\n' "${before[@]+"${before[@]}"}" | grep -Fxq -- "$f"; then new+=("$f"); fi
        done
        if [ "${#new[@]}" -gt 0 ]; then
            for f in "${new[@]}"; do log "new since apply.php: $f"; done
            apply_rollback "$backup" "the shop fails its test after apply.php"
            exit 1
        fi
        log "test: the same problems as before, none new: the change stands"
    fi
    cmd_snapshot "$note"
    echo "APPLY-OK"
}

# A failed apply: the database as it was right before it (the copy of cmd_apply).
apply_rollback() {
    local backup="$1" reason="$2"
    log "apply: $reason; the database goes back to how it was before"
    db_import "$backup/db.sql.gz"
    clear_cache
    cmd_settings intro || true
    warm_cache
    log "apply: rolled back"
}

# A failed deploy: modules/<module> and the database as they were right before it (the copies of cmd_deploy).
deploy_rollback() {
    local module="$1" backup="$2" reason="$3"
    log "deploy $module: $reason; the module folder and the database go back to how they were before the deploy"
    rm -rf "${PS_ROOT:?}/modules/$module"
    if [ -d "$backup/module" ]; then
        cp -a "$backup/module" "$PS_ROOT/modules/$module"
    fi
    db_import "$backup/db.sql.gz"
    clear_cache
    cmd_settings intro || true
    warm_cache
    log "deploy $module: rolled back"
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
    vlog "_PS_MODE_DEMO_ = $value in $file"
    clear_cache
    log "demo mode $1"
}

cmd_install_cron() {
    command -v crontab > /dev/null 2>&1 || die "crontab is not available"
    # The PATH of this session goes along: cron's own PATH misses where php and mysql often live.
    local line="$CRON_TIME PATH='$PATH' $DIR/demo-site.sh nightly >> $DIR/nightly.log 2>&1"
    if [ "$CRON_INSTALL" = "no" ]; then
        # Scheduled in a control panel (Hestia, Plesk rewrite the crontab from their own list): not in crontab here.
        { crontab -l 2> /dev/null | grep -v 'demo-site.sh nightly' || true; } | crontab -
        log "cron: not installed here (CRON_INSTALL=no); schedule this in the control panel: ${line#"$CRON_TIME "}  at  $CRON_TIME"
        return 0
    fi
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
vlog "demo-site.sh $command $*"
case "$command" in status | paths | help | -h | --help) ;; *) vsettings ;; esac
case "$command" in
    snapshot) lock; cmd_snapshot "$@" ;;
    restore) lock; cmd_restore "$@" ;;
    test) cmd_test ;;
    nightly) lock; log "nightly reset"; cmd_restore latest; cmd_test ;;
    deploy) lock; cmd_deploy "$@" ;;
    apply) lock; cmd_apply "$@" ;;
    activate) lock; cmd_activate "$@" ;;
    demo-mode) lock; cmd_demo_mode "$@" ;;
    settings) case "${1:-}" in "" | intro) ;; *) die "usage: demo-site.sh settings [intro]" ;; esac; lock; cmd_settings "${1:-}" ;;
    install-cron) cmd_install_cron ;;
    status) cmd_status ;;
    paths) echo "SNAPSHOT_DIR=$SNAPSHOT_DIR"; echo "PS_ROOT=$PS_ROOT" ;;
    *) sed -n '2,/^set -euo/p' "$0" | sed -e '/^set -euo/d' -e 's/^# \{0,1\}//'; [ "$command" = help ] ;;
esac
