// Uses Chrome DevTools and Node's built-in WebSocket; no test package is required.
// Run against the dedicated tests/Support/Checkout server, never a real application database.
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const base = 'http://127.0.0.1:18080';
const profile = await mkdtemp(join(tmpdir(), 'activefit-checkout-chrome-'));
const chrome = spawn(
    process.env.CHROME_BIN ?? '/usr/bin/google-chrome',
    [
        '--headless',
        '--no-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--remote-debugging-port=19222',
        `--user-data-dir=${profile}`,
        'about:blank',
    ],
    { stdio: 'ignore' },
);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
let ws;
try {
    let tabs;
    for (let attempt = 0; attempt < 80; attempt++) {
        try {
            tabs = await (await fetch('http://127.0.0.1:19222/json')).json();
            break;
        } catch {
            await sleep(100);
        }
    }
    assert(tabs?.length, 'Chrome did not start');
    ws = new WebSocket(
        tabs.find((tab) => tab.type === 'page').webSocketDebuggerUrl,
    );
    await new Promise((resolve, reject) => {
        ws.onopen = resolve;
        ws.onerror = reject;
    });
    let id = 0;
    const waiting = new Map();
    const errors = [];
    const redirects = [];
    const call = (method, params = {}) =>
        new Promise((resolve, reject) => {
            const next = ++id;
            waiting.set(next, { resolve, reject });
            ws.send(JSON.stringify({ id: next, method, params }));
        });
    ws.onmessage = async ({ data }) => {
        const message = JSON.parse(data);
        if (message.id) {
            const pending = waiting.get(message.id);
            waiting.delete(message.id);
            if (message.error)
                pending?.reject(new Error(JSON.stringify(message.error)));
            else pending?.resolve(message.result);
        }
        if (message.method === 'Runtime.exceptionThrown')
            errors.push(message.params.exceptionDetails.text);
        if (message.method === 'Fetch.requestPaused') {
            const { requestId, request } = message.params;
            if (request.url.startsWith('https://sandbox.przelewy24.pl/')) {
                redirects.push(request.url);
                await call('Fetch.fulfillRequest', {
                    requestId,
                    responseCode: 200,
                    responseHeaders: [
                        { name: 'Content-Type', value: 'text/html' },
                    ],
                    body: Buffer.from('<h1>Mock Przelewy24</h1>').toString(
                        'base64',
                    ),
                });
            } else if (
                request.url.startsWith(base) ||
                request.url.startsWith('data:')
            ) {
                await call('Fetch.continueRequest', { requestId });
            } else {
                await call('Fetch.failRequest', {
                    requestId,
                    errorReason: 'BlockedByClient',
                });
            }
        }
    };
    await call('Page.enable');
    await call('Runtime.enable');
    await call('Fetch.enable', { patterns: [{ urlPattern: '*' }] });
    const evaluate = async (expression) => {
        const result = await call('Runtime.evaluate', {
            expression,
            awaitPromise: true,
            returnByValue: true,
        });
        if (result.exceptionDetails)
            throw new Error(JSON.stringify(result.exceptionDetails));
        return result.result.value;
    };
    const until = async (expression) => {
        for (let attempt = 0; attempt < 120; attempt++) {
            try {
                if (await evaluate(expression)) return;
            } catch {
                /* Navigation replaces the execution context. */
            }
            await sleep(100);
        }
        throw new Error(`Timed out: ${expression}`);
    };
    const navigate = async (path) => {
        await call('Page.navigate', { url: base + path });
    };
    const fill = async (prefix, first, email) =>
        evaluate(`(() => {
        const values = { '${prefix}-first-name': ${JSON.stringify(first)}, '${prefix}-last-name': 'Testowy', '${prefix}-email': ${JSON.stringify(email)}, '${prefix}-phone': '+48 600 111 222' };
        for (const [id, value] of Object.entries(values)) {
            const input = document.getElementById(id); input.value = value; input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    })()`);
    const state = async () =>
        (await fetch(`${base}/__checkout_test/state`)).json();
    await call('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await navigate('/cennik');
    await until(`!!document.querySelector('a[href*="/kup-karnet?plan="]')`);
    await evaluate(
        `document.querySelector('a[href*="/kup-karnet?plan="]').click()`,
    );
    await until(`!!document.getElementById('purchaser-first-name')`);
    await evaluate(
        `document.querySelectorAll('input[name="membership_plan_id"]')[1].click(); document.querySelectorAll('input[name="holder_choice"]')[1].click()`,
    );
    await until(`!!document.getElementById('holder-email')`);
    await evaluate(`document.querySelector('button[type="submit"]').click()`);
    assert.equal(
        (await state()).orders,
        0,
        'Required fields must block submission',
    );
    await fill('purchaser', 'Ania', 'buyer@example.test');
    await fill('holder', 'Jan', 'holder@example.test');
    await evaluate(`document.querySelector('input[type="checkbox"]').click()`);
    assert.match(
        await evaluate(
            `document.querySelector('aside[aria-label="Podsumowanie zakupu"]').textContent`,
        ),
        /120,50/,
    );
    await evaluate(
        `document.querySelector('button[type="submit"]').click(); document.querySelector('button[type="submit"]').click()`,
    );
    await until(`location.hostname === 'sandbox.przelewy24.pl'`);
    let data = await state();
    assert.equal(data.orders, 1);
    assert.equal(data.payments, 1);
    assert.equal(data.memberships, 0);
    assert.equal(data.order.buyer, 'buyer@example.test');
    assert.equal(data.order.holder, 'holder@example.test');
    assert.equal(data.order.recipient, 'holder@example.test');
    assert.equal(data.order.account, null);
    assert(
        redirects.some((url) => url.endsWith('/trnRequest/browser-test-token')),
    );
    await navigate(
        `/platnosc/powrot?zamowienie=${data.lastReference}&status=paid`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('oczekuje na opłatę')`,
    );
    assert.equal(
        (await state()).memberships,
        0,
        'Browser return cannot issue a pass',
    );
    const confirmation = await fetch(`${base}/__checkout_test/confirm`, {
        method: 'POST',
    });
    assert.equal(confirmation.status, 200);
    await evaluate(
        `[...document.querySelectorAll('button')].find((button) => button.textContent.includes('Odśwież stan')).click()`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('czeka na aktywację')`,
    );
    data = await state();
    assert.equal(data.memberships, 1);
    assert.equal(data.deliveries, 1);
    assert.equal(data.membership.startsOn, null);
    assert.equal(data.membership.balance, 8);
    await navigate('/kup-karnet');
    await until(`!!document.getElementById('purchaser-first-name')`);
    await evaluate(`document.cookie = 'checkout_test_gateway=timeout; path=/'`);
    await fill('purchaser', 'Ania', 'buyer@example.test');
    await evaluate(
        `document.querySelector('input[type="checkbox"]').click(); document.querySelector('button[type="submit"]').click()`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('Sprawdzamy płatność')`,
    );
    assert.equal(
        await evaluate(`!!document.querySelector('button[type="submit"]')`),
        false,
    );
    assert.equal((await state()).orders, 2);
    assert.equal((await state()).memberships, 1);
    await call('Emulation.setDeviceMetricsOverride', {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    await navigate('/kup-karnet');
    await until(`!!document.getElementById('purchaser-first-name')`);
    await evaluate(
        `document.cookie = 'checkout_test_gateway=; path=/'; document.querySelectorAll('input[name="holder_choice"]')[1].click()`,
    );
    await until(`!!document.getElementById('holder-email')`);
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
        'Mobile checkout overflows',
    );
    const shot = await call('Page.captureScreenshot', {
        captureBeyondViewport: true,
    });
    await writeFile(
        '/tmp/activefit-checkout-mobile.png',
        Buffer.from(shot.data, 'base64'),
    );
    assert.deepEqual(errors, [], 'Unexpected browser JavaScript exceptions');
    console.log(
        'PASS: desktop purchase, dynamic holder/plan fields, validation, duplicate submit, mocked redirect, untrusted return, verified pending activation, timeout and mobile layout.',
    );
} finally {
    ws?.close();
    chrome.kill('SIGTERM');
    await new Promise((resolve) => chrome.once('exit', resolve));
    await rm(profile, { recursive: true, force: true });
}
