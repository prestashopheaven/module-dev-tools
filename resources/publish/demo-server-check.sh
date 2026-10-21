#!/usr/bin/env bash
# Checks a server for the demo shop (ps-module demo-site check): what is there and what is missing. Changes nothing.
# Usage: demo-server-check.sh <ps-root> <shop-url>
PS_ROOT="${1:-}"
URL="${2:-}"
PHP="${DEMO_SITE_PHP:-php}"
problems=0

ok() { printf '  ok       %s\n' "$*"; }
info() { printf '  info     %s\n' "$*"; }
missing() { printf '  MISSING  %s\n' "$*"; problems=$((problems + 1)); }
# Verbose only (VERBOSE=1, set by ps-module -v demo-site check): more detail. Never prints IP addresses.
VERBOSE="${VERBOSE:-0}"
detail() { if [ "$VERBOSE" = 1 ]; then printf '  [v]      %s\n' "$*"; fi; }

echo "System"
info "$( (. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME") || uname -sr)"
info "user $(id -un), groups: $(id -Gn)"
for panel in /usr/local/directadmin /usr/local/psa /usr/local/cpanel /usr/local/hestia /usr/local/vesta; do
    [ -d "$panel" ] && info "control panel: $(basename "$panel")"
done
if sudo -n true 2>/dev/null; then info "sudo without password: yes"; else info "sudo without password: no"; fi
info "free disk in home: $(df -h "$HOME" | awk 'NR==2 {print $4}')"

echo "Commands"
for cmd in "$PHP" mysql mysqldump rsync tar gzip curl crontab unzip flock; do
    if command -v "$cmd" >/dev/null 2>&1; then ok "$cmd"; else missing "$cmd"; fi
done
versions=$(ls /usr/bin/ 2>/dev/null | grep -E '^php[0-9]+\.[0-9]+$' | tr '\n' ' ')
[ -n "$versions" ] && info "PHP versions installed: $versions(set DEMO_SITE_PHP to choose one for the command line)"
if command -v "$PHP" >/dev/null 2>&1; then
    info "$("$PHP" -r 'echo "PHP ", PHP_VERSION, ", memory_limit ", ini_get("memory_limit");')"
    "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") && version_compare(PHP_VERSION, "8.6.0", "<") ? 0 : 1);' \
        && ok "PHP version fits PrestaShop 9.2 (8.1 - 8.5)" || missing "PHP 8.1 - 8.5 for PrestaShop 9.2"
    for ext in curl dom fileinfo gd iconv intl json mbstring openssl pdo_mysql simplexml zip; do
        if "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);"; then ok "php-$ext"; else missing "php-$ext (CLI)"; fi
    done
fi

echo "Web server"
for svc in apache2 httpd nginx lsws litespeed; do
    pgrep -x "$svc" >/dev/null 2>&1 && info "$svc runs as: $(ps -o user= -C "$svc" 2>/dev/null | sort -u | tr '\n' ' ')"
done
[ -d /etc/apache2 ] || [ -d /etc/httpd ] && info "Apache is installed (PrestaShop's .htaccess works)" || info "no Apache: nginx alone ignores .htaccess (PrestaShop needs its own nginx template)"
[ -d /etc/nginx ] && info "nginx is installed"
if [ -n "$URL" ]; then
    domain=$(echo "$URL" | sed -E 's#^https?://([^/:]+).*#\1#')
    if [ -d "$HOME/conf/web/$domain" ]; then
        info "Hestia web config for $domain: $(ls "$HOME/conf/web/$domain" | tr '\n' ' ')"
    fi
fi
fpm=$(ps -eo user=,comm= 2>/dev/null | awk '$2 ~ /php-fpm/ && $1 != "root" {print $1}' | sort -u | tr '\n' ' ')
[ -n "$fpm" ] && info "php-fpm pools run as: $fpm"
pgrep -x mysqld >/dev/null 2>&1 || pgrep -x mariadbd >/dev/null 2>&1 && info "MySQL/MariaDB server runs here" || info "no MySQL/MariaDB server process found here"

echo "Shop folder"
if [ -z "$PS_ROOT" ]; then
    missing "DEMO_SITE_PS_ROOT is not set"
elif [ -d "$PS_ROOT" ]; then
    info "$PS_ROOT exists, owner $(stat -c '%U:%G' "$PS_ROOT"), entries: $(find "$PS_ROOT" -mindepth 1 -maxdepth 1 -printf '%f ' | head -c 300)"
    [ -w "$PS_ROOT" ] && ok "writable for $(id -un)" || missing "$PS_ROOT is not writable for $(id -un)"
    [ -f "$PS_ROOT/app/config/parameters.php" ] && info "PrestaShop is installed (app/config/parameters.php)" || info "no PrestaShop installed yet"
    info "free disk there: $(df -h "$PS_ROOT" | awk 'NR==2 {print $4}')"
    if [ -f "$PS_ROOT/app/config/parameters.php" ]; then
        # The database settings stay on the server: PHP reads them from the shop and prints only server variables.
        "$PHP" -r '
            $p = (require $argv[1])["parameters"];
            try {
                $host = (string) $p["database_host"];
                $dsn = "mysql:host=" . $host . (!empty($p["database_port"]) ? ";port=" . $p["database_port"] : "") . ";dbname=" . $p["database_name"];
                $db = new PDO($dsn, (string) $p["database_user"], (string) $p["database_password"], [PDO::ATTR_TIMEOUT => 10]);
                echo "  ok       database connection\n";
                $v = $db->query("SELECT VERSION()")->fetchColumn();
                echo "  info     database server ", $v, "\n";
                foreach ($db->query("SHOW VARIABLES WHERE Variable_name IN (\"wait_timeout\", \"interactive_timeout\", \"max_allowed_packet\", \"net_read_timeout\", \"net_write_timeout\", \"max_connections\")") as $row) {
                    echo "  info     ", $row[0], " = ", $row[1], "\n";
                }
                $wait = (int) $db->query("SELECT @@GLOBAL.wait_timeout")->fetchColumn();
                $packet = (int) $db->query("SELECT @@GLOBAL.max_allowed_packet")->fetchColumn();
                if ($wait < 300) echo "  MISSING  wait_timeout of at least 300 s (PrestaShop downloads language packs between queries)\n";
                if ($packet < 64 * 1048576) echo "  MISSING  max_allowed_packet of at least 64M (language packs, snapshots)\n";
            } catch (Throwable $e) {
                echo "  MISSING  database connection: ", get_class($e), " ", preg_replace("/\x27[^\x27]*\x27@\x27[^\x27]*\x27/", "***", $e->getMessage()), "\n";
            }
        ' "$PS_ROOT/app/config/parameters.php" > "$HOME/.demo-check-db.$$" 2>&1
        cat "$HOME/.demo-check-db.$$"
        problems=$((problems + $(grep -c MISSING "$HOME/.demo-check-db.$$")))
        rm -f "$HOME/.demo-check-db.$$"
    fi
else
    parent=$(dirname "$PS_ROOT")
    info "$PS_ROOT does not exist yet"
    [ -w "$parent" ] && ok "$parent is writable: the folder can be made" || missing "$parent is not writable for $(id -un)"
fi

echo "Shop URL"
if [ -n "$URL" ] && command -v curl >/dev/null 2>&1; then
    code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' "$URL/" 2>/dev/null)
    info "$URL/ answers HTTP ${code:-none} (from the server itself)"
    detail "DNS from the server: $(getent hosts "$(echo "$URL" | sed -E 's#^https?://([^/:]+).*#\1#')" | wc -l) address(es); own addresses: $(hostname -I 2>/dev/null | wc -w)"
    detail "timing: $(curl -s -o /dev/null -m 15 -w 'connect %{time_connect}s, tls %{time_appconnect}s, first byte %{time_starttransfer}s, total %{time_total}s, %{num_redirects} redirect(s)' "$URL/" 2>/dev/null)"
    if [ "$code" = "000" ]; then
        hostname=$(echo "$URL" | sed -E 's#^https?://([^/:]+).*#\1#')
        port=443
        case "$URL" in http://*) port=80 ;; esac
        local_code=$(curl -s -o /dev/null -m 15 -w '%{http_code}' --connect-to "$hostname:$port:127.0.0.1:$port" "$URL/" 2>/dev/null)
        if [ "$local_code" != "000" ]; then
            info "via 127.0.0.1 it answers HTTP $local_code: set DEMO_SITE_CONNECT_TO=$hostname:$port:127.0.0.1:$port (smoke test)"
        else
            # Control panels (Hestia, Plesk) let the web server listen on the public address only.
            found=""
            n=0
            for address in $(hostname -I 2>/dev/null); do
                n=$((n + 1))
                target="$address"
                case "$address" in *:*) target="[$address]" ;; esac
                c=$(curl -s -o /dev/null -m 10 -w '%{http_code}' --connect-to "$hostname:$port:$target:$port" "$URL/" 2>/dev/null)
                if [ "$c" != "000" ] && [ -z "$found" ]; then
                    found="$hostname:$port:$target:$port"
                    info "via the server's own address #$n it answers HTTP $c: set DEMO_SITE_CONNECT_TO=$found (smoke test)"
                fi
            done
            [ -z "$found" ] && missing "the server cannot reach $URL/ itself, also not via 127.0.0.1 or its own addresses (needed for the smoke test); DNS from the server: $(getent hosts "$hostname" | wc -l) address(es)"
        fi
    fi
fi

if [ -n "$URL" ] && [ -d "$PS_ROOT" ] && [ -w "$PS_ROOT" ] && command -v curl >/dev/null 2>&1; then
    echo "PHP behind the web server"
    probe="psh-probe-$(date +%s)-$RANDOM.php"
    # Only the variables that tell whether PHP sees HTTPS (PrestaShop: Tools::usingSecureMode()).
    cat > "$PS_ROOT/$probe" <<'PHP'
<?php
foreach (['HTTPS', 'SSL', 'REDIRECT_HTTPS', 'HTTP_SSL', 'HTTP_X_FORWARDED_PROTO', 'SERVER_PORT', 'SERVER_SOFTWARE'] as $k) {
    echo $k, '=', isset($_SERVER[$k]) ? var_export($_SERVER[$k], true) : '(unset)', "\n";
}
echo 'PHP=', PHP_VERSION, ' ', PHP_SAPI, "\n";
PHP
    route=()
    hostname=$(echo "$URL" | sed -E 's#^https?://([^/:]+).*#\1#')
    port=443
    case "$URL" in http://*) port=80 ;; esac
    if [ "$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$URL/$probe" 2>/dev/null)" = "000" ]; then
        for address in 127.0.0.1 $(hostname -I 2>/dev/null); do
            target="$address"
            case "$address" in *:*) target="[$address]" ;; esac
            if [ "$(curl -s -o /dev/null -m 10 -w '%{http_code}' --connect-to "$hostname:$port:$target:$port" "$URL/$probe" 2>/dev/null)" != "000" ]; then
                route=(--connect-to "$hostname:$port:$target:$port")
                break
            fi
        done
    fi
    detail "probe $probe: $([ "${#route[@]}" -gt 0 ] && echo "via this server's own address" || echo 'over the public address')"
    curl -s -m 15 "${route[@]}" "$URL/$probe" 2>/dev/null | head -n 10 | sed 's/^/  info     /'
    rm -f "$PS_ROOT/$probe"
fi

echo
[ "$problems" -eq 0 ] && echo "No missing items." || echo "$problems missing item(s)."
exit 0
