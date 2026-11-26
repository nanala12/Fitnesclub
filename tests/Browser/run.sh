#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
# Fixed disposable configuration; never inherit the club's database, SMTP or key.
export APP_ENV=testing
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
export APP_URL=http://127.0.0.1:18080
export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/activefit-checkout-browser.sqlite
export DB_URL=''
export SESSION_DRIVER=file
export SESSION_COOKIE=checkout_browser_test
export CACHE_STORE=array
export MAIL_MAILER=array

command -v "${CHROME_BIN:-google-chrome}" >/dev/null || { echo 'Chrome is required for browser regression.' >&2; exit 1; }
exec 9>/tmp/activefit-checkout-browser.lock
flock -n 9 || { echo 'Browser regression is already running.' >&2; exit 1; }
node --input-type=module <<'JS'
import net from 'node:net';
const listeners = [];
try {
    for (const port of [18080, 19222]) {
        const server = net.createServer();
        await new Promise((resolve, reject) => { server.once('error', reject); server.listen(port, '127.0.0.1', resolve); });
        listeners.push(server);
    }
} catch {
    process.stderr.write('A browser test port is occupied.\n');
    process.exitCode = 1;
} finally {
    for (const server of listeners) await new Promise((resolve) => server.close(resolve));
}
JS
activefit_browser_container=""
if [[ "${1:-}" == "--docker" ]]; then
    activefit_browser_container="activefit-browser-fixture-$$"
    docker compose run --rm --no-deps -T --name "$activefit_browser_container" -p 127.0.0.1:18080:8000 \
        -e APP_ENV -e APP_KEY -e APP_URL -e DB_CONNECTION -e DB_DATABASE -e DB_URL \
        -e SESSION_DRIVER -e SESSION_COOKIE -e CACHE_STORE -e MAIL_MAILER \
        app sh -c 'php tests/Support/Checkout/browser-seed.php && php -S 0.0.0.0:8000 -t public tests/Support/Checkout/browser-router.php' > /tmp/activefit-browser-server.log 2>&1 &
else
    php tests/Support/Checkout/browser-seed.php
    php -S 127.0.0.1:18080 -t public tests/Support/Checkout/browser-router.php > /tmp/activefit-browser-server.log 2>&1 &
fi
activefit_browser_server_pid=$!
cleanup() {
    if [[ -n "$activefit_browser_container" ]]; then
        docker stop "$activefit_browser_container" >/dev/null 2>&1 || true
    else
        kill "$activefit_browser_server_pid" 2>/dev/null || true
    fi
    wait "$activefit_browser_server_pid" 2>/dev/null || true
}
trap cleanup EXIT
for attempt in {1..50}; do
    if curl --silent --fail http://127.0.0.1:18080/__checkout_test/state > /dev/null; then break; fi
    if ! kill -0 "$activefit_browser_server_pid" 2>/dev/null; then echo 'Browser fixture server stopped.' >&2; exit 1; fi
    sleep 0.1
done
if node -e 'process.exit(typeof WebSocket === "function" ? 0 : 1)'; then
    node tests/Browser/checkout.mjs
else
    node --experimental-websocket tests/Browser/checkout.mjs
fi
