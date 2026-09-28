#!/bin/bash
# The CakePHP skeleton (tests/Fixtures/app, made by tests/create-app.sh) on PHP-FPM behind
# nginx and on swerve, same machine, same process count, opcache on, debug off:
#   /bench/json     a controller action returning JSON
#   /bench/session  a controller action that reads and writes the session (PHP's file handler);
#                   each wrk thread cycles through 16 sessions created beforehand
# Usage: benchmarks/run.sh [workers] > benchmarks/results.txt
set -eu
here=$(cd "$(dirname "$0")" && pwd)
workers=${1:-4}
fpm_port=18860 swerve_port=18861
ext=/home/frode/dev/phasync-ext/modules/phasync.so
app=$(mktemp -d /tmp/swerve-cakephp-bench.XXXXXX)
trap 'kill $(jobs -p) 2>/dev/null; rm -rf "$app"' EXIT

# A copy of the test application in production mode: FPM passes no environment variables
cp -R "$here/../tests/Fixtures/app/." "$app/"
rm -rf "$app/tmp/cache"/*/* "$app/vendor/phasync/swerve-cakephp"
cp -RL "$here/../tests/Fixtures/package" "$app/vendor/phasync/swerve-cakephp"
sed -i "s/env('DEBUG', true)/env('DEBUG', false)/" "$app/config/app_local.php"
sed -i "s|env('APP_FULL_BASE_URL', false)|'http://127.0.0.1'|" "$app/config/app.php"

wait_up() { for _ in $(seq 100); do curl -s -o /dev/null "http://127.0.0.1:$1/bench/json" && return; sleep 0.1; done; echo "not up on $1" >&2; exit 1; }

# 64 sessions, 16 per wrk thread
sessions() {
    for _ in $(seq 64); do
        curl -s -D - -o /dev/null "http://127.0.0.1:$1/bench/session" | sed -n 's/^Set-Cookie: PHPSESSID=\([^;]*\).*/\1/p'
    done | paste -sd, -
}
lua() {
    cat > "$app/session.lua" <<LUA
local ids = { "$(echo "$1" | sed 's/,/", "/g')" }
local counter = 1
function setup(thread) thread:set("id", counter); counter = counter + 1 end
function init() n = 0 end
function request()
    n = n + 1
    return wrk.format(nil, nil, { Cookie = "PHPSESSID=" .. ids[(id - 1) * 16 + (n % 16) + 1] })
end
LUA
}

bench() { # name port
    for path in /bench/json /bench/session; do
        echo "### $1 $path"
        curl -s -i "http://127.0.0.1:$2$path" | tr -d '\r'
        echo
        if [ $path = /bench/session ]; then
            lua "$(sessions "$2")"
            flock /home/frode/dev/fpm-bench/bench.lock wrk -t4 -c64 -d10s -s "$app/session.lua" "http://127.0.0.1:$2$path"
        else
            flock /home/frode/dev/fpm-bench/bench.lock wrk -t4 -c64 -d10s "http://127.0.0.1:$2$path"
        fi
    done
}

echo "# $(date -u +%Y-%m-%dT%H:%MZ) $(lscpu | sed -n 's/^Model name: *//p'), $(nproc) threads"
echo "# PHP $(php -r 'echo PHP_VERSION;'), CakePHP $(sed -n '$p' "$app/vendor/cakephp/cakephp/VERSION.txt"), swerve $(composer -d "$app" show phasync/swerve 2>/dev/null | sed -n 's/^versions : \* //p'), phasync-ext $(php -d extension=$ext -r 'echo phpversion("phasync");'), $workers workers"

"/home/frode/dev/fpm-bench/serve.sh" "$app/webroot" $fpm_port "$workers" & fpm=$!
wait_up $fpm_port
bench "php-fpm $workers children" $fpm_port
kill $fpm; wait $fpm 2>/dev/null || true

for args in "" "-d extension=$ext"; do
    php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0 $args "$app/vendor/bin/swerve" -q --no-access-log --workers="$workers" --http=127.0.0.1:$swerve_port "$app/swerve.php" & swerve=$!
    wait_up $swerve_port
    bench "swerve $workers workers${args:+ with phasync-ext}" $swerve_port
    kill $swerve; wait $swerve 2>/dev/null || true
done
