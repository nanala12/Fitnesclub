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
    await navigate('/login');
    await until(`!!document.querySelector('form')`);
    await evaluate(`fetch('/__checkout_test/login-admin', { method: 'POST' })`);
    await navigate('/admin');
    await until(`!!document.querySelector('[data-metric="active"]')`);
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="active"]').textContent.trim()`,
        ),
        '0',
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="sales-total"]').textContent.trim()`,
        ),
        '0,00 zł',
    );
    await evaluate(`fetch('/logout', { method: 'POST' })`);
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
    const pdf = await evaluate(`(async () => {
        const link = [...document.querySelectorAll('a')].find((item) => item.textContent.includes('Pobierz PDF karnetu'));
        if (!link) throw new Error('Missing PDF download link');
        const response = await fetch(link.href);
        const bytes = new Uint8Array(await response.arrayBuffer());
        return { status: response.status, type: response.headers.get('Content-Type'), prefix: new TextDecoder().decode(bytes.slice(0, 5)) };
    })()`);
    assert.deepEqual(pdf, {
        status: 200,
        type: 'application/pdf',
        prefix: '%PDF-',
    });
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
    await evaluate(`fetch('/__checkout_test/deliver', { method: 'POST' })`);
    await evaluate(`fetch('/__checkout_test/login-admin', { method: 'POST' })`);
    await call('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await navigate('/admin/wysylki-pdf');
    await until(
        `document.querySelector('h1')?.textContent.includes('Wysyłki PDF')`,
    );
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('holder@example.test')`,
        ),
        false,
    );
    await evaluate(
        `[...document.querySelectorAll('button')].find((button) => button.textContent.includes('Wyślij ponownie')).click()`,
    );
    await until(`!!document.querySelector('[role="dialog"]')`);
    await evaluate(
        `[...document.querySelector('[role="dialog"]').querySelectorAll('button')].find((button) => button.textContent.includes('Anuluj')).click()`,
    );
    assert.equal((await state()).deliveries, 1);
    await evaluate(
        `[...document.querySelectorAll('button')].find((button) => button.textContent.includes('Wyślij ponownie')).click()`,
    );
    await until(`!!document.querySelector('[role="dialog"]')`);
    await evaluate(
        `[...document.querySelector('[role="dialog"]').querySelectorAll('button')].find((button) => button.textContent.includes('Potwierdź wysyłkę')).click()`,
    );
    await until(
        `!document.querySelector('[role="dialog"]') && document.body.textContent.includes('Ponowna wysyłka')`,
    );
    assert.equal((await state()).deliveries, 2);
    await evaluate(`document.cookie = 'checkout_test_mail=fail; path=/'`);
    await evaluate(`fetch('/__checkout_test/deliver', { method: 'POST' })`);
    await navigate('/admin/wysylki-pdf');
    await until(`document.body.textContent.includes('Wysyłka nieudana')`);
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    await call('Emulation.setDeviceMetricsOverride', {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    const deliveryShot = await call('Page.captureScreenshot', {
        captureBeyondViewport: true,
    });
    await writeFile(
        '/tmp/activefit-deliveries-mobile.png',
        Buffer.from(deliveryShot.data, 'base64'),
    );
    await evaluate(
        `fetch('/__checkout_test/login-customer', { method: 'POST' })`,
    );
    await navigate('/moje-zamowienia');
    await until(
        `document.querySelector('h1')?.textContent.includes('Moje zamówienia')`,
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('article').length`),
        3,
        'Guest purchases must remain separate',
    );
    for (const label of [
        'Recepcja',
        'Gotówka',
        'Karta w recepcji',
        'jest aktywny',
        'ustalony termin',
        'okres karnetu zakończony',
    ]) {
        assert.equal(
            await evaluate(
                `document.body.textContent.includes(${JSON.stringify(label)})`,
            ),
            true,
            label,
        );
    }
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    const historyShot = await call('Page.captureScreenshot', {
        captureBeyondViewport: true,
    });
    await writeFile(
        '/tmp/activefit-orders-mobile.png',
        Buffer.from(historyShot.data, 'base64'),
    );
    await evaluate(
        `[...document.querySelectorAll('article')].find((row) => row.textContent.includes('ustalony termin')).querySelector('a').click()`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('ustalony termin')`,
    );
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('bez kolejnej aktywacji')`,
        ),
        true,
    );
    assert.equal(
        await evaluate(`!!document.querySelector('button[type="submit"]')`),
        false,
    );
    await navigate('/moje-karnety');
    await until(
        `document.querySelector('h1')?.textContent.includes('Moje karnety')`,
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('article').length`),
        3,
        'Guest passes must not enter customer panel',
    );
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    const ownShot = await call('Page.captureScreenshot', {
        captureBeyondViewport: true,
    });
    await writeFile(
        '/tmp/activefit-memberships-mobile.png',
        Buffer.from(ownShot.data, 'base64'),
    );
    await evaluate(
        `[...document.querySelectorAll('article')].find((row) => row.textContent.includes('ustalony termin')).querySelector('a').click()`,
    );
    await until(`document.body.textContent.includes('Historia wejść')`);
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('ponownej aktywacji')`,
        ),
        true,
    );
    const ownPdf = await evaluate(
        `(async () => { const link = [...document.querySelectorAll('a')].find((item) => item.textContent.includes('Pobierz PDF')); const response = await fetch(link.href); const bytes = new Uint8Array(await response.arrayBuffer()); return { status: response.status, prefix: new TextDecoder().decode(bytes.slice(0, 5)) }; })()`,
    );
    assert.deepEqual(ownPdf, { status: 200, prefix: '%PDF-' });
    await navigate('/kup-karnet');
    await until(`!!document.getElementById('purchaser-first-name')`);
    await evaluate(
        `document.cookie = 'checkout_test_gateway=reject; path=/'; document.querySelector('input[type="checkbox"]').click(); document.querySelector('button[type="submit"]').click()`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('Płatność nieudana')`,
    );
    const failedReference = (await state()).lastReference;
    const failedOrders = (await state()).orders;
    await navigate('/moje-zamowienia');
    await until(`document.querySelectorAll('article').length === 4`);
    await evaluate(
        `[...document.querySelectorAll('article')].find((row) => row.textContent.includes('Płatność nieudana')).querySelector('a').click()`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('Płatność nieudana')`,
    );
    await evaluate(
        `document.cookie = 'checkout_test_gateway=; path=/'; document.querySelector('button[type="submit"]').click()`,
    );
    await until(`location.hostname === 'sandbox.przelewy24.pl'`);
    assert.equal(
        (await state()).orders,
        failedOrders,
        'Retry must retain the same order',
    );
    await navigate(
        `/platnosc/powrot?zamowienie=${failedReference}&status=paid`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('oczekuje na opłatę')`,
    );
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('karnet jest aktywny')`,
        ),
        false,
    );
    await call('Emulation.setDeviceMetricsOverride', {
        width: 1440,
        height: 1000,
        deviceScaleFactor: 1,
        mobile: false,
    });
    await navigate('/moje-zamowienia');
    await until(`document.querySelectorAll('article').length === 4`);
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    await evaluate(
        `fetch('/__checkout_test/confirm-latest', { method: 'POST' })`,
    );
    await navigate('/moje-karnety');
    await until(`document.querySelectorAll('article').length === 4`);
    await evaluate(
        `[...document.querySelectorAll('article')].find((row) => row.textContent.includes('czeka na aktywację')).querySelector('a').click()`,
    );
    await until(`document.body.textContent.includes('Historia wejść')`);
    assert.equal(
        await evaluate(`document.body.textContent.includes('Ważny od')`),
        false,
        'Pending pass must not show invented start date',
    );
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('pierwszej wizycie')`,
        ),
        true,
    );
    await evaluate(`fetch('/__checkout_test/login-staff', { method: 'POST' })`);
    await navigate('/recepcja/karnety');
    await until(
        `document.querySelector('h1')?.textContent.includes('Obsługa karnetów')`,
    );
    await evaluate(
        `document.querySelector('a[href="/recepcja/karnety/sprzedaz"]').click()`,
    );
    await until(`!!document.getElementById('purchaser-first-name')`);
    await fill('purchaser', 'Maria', 'reception@example.test');
    await evaluate(
        `(() => { const select = document.getElementById('plan'); select.value = [...select.options].find((item) => item.textContent.includes('8 wejść')).value; select.dispatchEvent(new Event('change', { bubbles: true })); document.querySelector('input[value="card"]').click(); document.querySelector('button[type="submit"]').click(); })()`,
    );
    const beforeSale = (await state()).orders;
    const dialogButton = async (text) =>
        evaluate(
            `[...document.querySelector('[role="dialog"]').querySelectorAll('button')].find((button) => button.textContent.includes(${JSON.stringify(text)})).click()`,
        );
    const pageButton = async (text) =>
        evaluate(
            `[...document.querySelectorAll('button')].find((button) => button.textContent.includes(${JSON.stringify(text)})).click()`,
        );
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Anuluj');
    assert.equal((await state()).orders, beforeSale);
    await evaluate(`document.querySelector('button[type="submit"]').click()`);
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Zapisz zamówienie');
    await until(
        `document.body.textContent.includes('Oczekuje na potwierdzenie opłaty w recepcji')`,
    );
    assert.equal((await state()).orders, beforeSale + 1);
    assert.equal(
        await evaluate(`document.body.textContent.includes('Aktywuj karnet')`),
        false,
    );
    await pageButton('Potwierdź otrzymanie opłaty');
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Potwierdź');
    await until(`document.body.textContent.includes('Aktywuj karnet')`);
    assert.equal((await state()).latestMembership.startsOn, null);
    assert.equal((await state()).latestMembership.balance, 8);
    await pageButton('Aktywuj karnet');
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Anuluj');
    assert.equal((await state()).latestMembership.startsOn, null);
    await pageButton('Aktywuj karnet');
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Potwierdź');
    await until(`document.body.textContent.includes('Odlicz jedno wejście')`);
    assert.equal(
        (await state()).latestMembership.balance,
        8,
        'Activation must not redeem an entry',
    );
    assert.equal(
        await evaluate(`document.body.textContent.includes('Aktywuj karnet')`),
        false,
    );
    const beforeEntry = (await state()).entries;
    await pageButton('Odlicz jedno wejście');
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Potwierdź');
    await until(
        `!document.querySelector('[role="dialog"]') && document.body.textContent.includes('jedno wejście')`,
    );
    assert.equal((await state()).latestMembership.balance, 7);
    assert.equal((await state()).entries, beforeEntry + 1);
    await navigate('/recepcja/karnety');
    await until(`!!document.getElementById('holder-search')`);
    await evaluate(
        `(() => { const field = document.getElementById('holder-search'); field.value = '0048 600 111 222'; field.dispatchEvent(new Event('input', { bubbles: true })); })()`,
    );
    await evaluate(`document.querySelector('button[type="submit"]').click()`);
    await until(`document.querySelectorAll('article').length >= 2`);
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('Maria Testowy') && document.body.textContent.includes('Jan Testowy')`,
        ),
        true,
        'Shared phone must show separate holders',
    );
    assert.equal(
        await evaluate(`location.search.includes('600')`),
        false,
        'Search phone must not enter URL',
    );
    await call('Emulation.setDeviceMetricsOverride', {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    const staffShot = await call('Page.captureScreenshot', {
        captureBeyondViewport: true,
    });
    await writeFile(
        '/tmp/activefit-staff-mobile.png',
        Buffer.from(staffShot.data, 'base64'),
    );
    await evaluate(
        `fetch('/__checkout_test/login-empty-customer', { method: 'POST' })`,
    );
    await navigate('/moje-karnety');
    await until(
        `document.body.textContent.includes('Nie masz jeszcze karnetów')`,
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('article').length`),
        0,
    );
    await evaluate(`fetch('/__checkout_test/login-admin', { method: 'POST' })`);
    await navigate('/admin/sprzedaz');
    await until(
        `document.querySelector('h1')?.textContent.includes('Sprzedaż i karnety')`,
    );
    await evaluate(
        `(() => { const method = document.getElementById('method'); method.value = 'card'; method.dispatchEvent(new Event('change', { bubbles: true })); document.querySelectorAll('form')[0].querySelector('button[type="submit"]').click(); })()`,
    );
    await until(
        `document.querySelectorAll('article').length > 0 && [...document.querySelectorAll('article')].every((row) => row.textContent.includes('Karta w recepcji'))`,
    );
    await evaluate(
        `(() => { const field = document.getElementById('sales-phone'); field.value = '0048 600 111 222'; field.dispatchEvent(new Event('input', { bubbles: true })); })()`,
    );
    await evaluate(
        `document.querySelectorAll('form')[1].querySelector('button[type="submit"]').click()`,
    );
    await until(
        `document.querySelectorAll('article').length >= 2 && document.getElementById('method').value === ''`,
    );
    assert.equal(await evaluate(`location.search.includes('600')`), false);
    await pageButton('Wyczyść wyszukiwanie');
    await until(`document.getElementById('sales-phone').value === ''`);
    await evaluate(
        `[...document.querySelectorAll('article')].find((row) => row.textContent.includes('Maria Testowy')).querySelector('a').click()`,
    );
    await until(`document.body.textContent.includes('Próby płatności')`);
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('Sprzedaż zapisał') && document.body.textContent.includes('Potwierdzona')`,
        ),
        true,
    );
    await evaluate(
        `[...document.querySelectorAll('a')].find((link) => link.textContent.includes('Historia operacji tego zakupu')).click()`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('Historia zmian')`,
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('[data-audit-id]').length`),
        5,
        'Audit must contain only this receipt sale and visit',
    );
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('reception@example.test')`,
        ),
        false,
    );
    await evaluate(
        `[...document.querySelectorAll('a')].find((link) => link.textContent.includes('Wróć do szczegółów sprzedaży')).click()`,
    );
    await until(`document.body.textContent.includes('Próby płatności')`);
    await evaluate(
        `[...document.querySelectorAll('a')].find((link) => link.textContent.includes('Wysyłki PDF tego zakupu')).click()`,
    );
    await until(
        `document.querySelector('h1')?.textContent.includes('Wysyłki PDF')`,
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('article').length`),
        1,
        'Delivery filter must exclude other orders',
    );
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('reception@example.test')`,
        ),
        false,
    );
    await navigate('/admin/sprzedaz');
    await until(
        `document.querySelector('h1')?.textContent.includes('Sprzedaż i karnety')`,
    );
    await call('Emulation.setDeviceMetricsOverride', {
        width: 390,
        height: 844,
        deviceScaleFactor: 1,
        mobile: true,
    });
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    const salesShot = await call('Page.captureScreenshot', {
        captureBeyondViewport: true,
    });
    await writeFile(
        '/tmp/activefit-admin-sales-mobile.png',
        Buffer.from(salesShot.data, 'base64'),
    );
    await navigate('/admin');
    await until(`!!document.querySelector('[data-metric="active"]')`);
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="customers"]').textContent.trim()`,
        ),
        '2',
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="active"]').textContent.trim()`,
        ),
        '2',
        'Pending/scheduled/expired passes must not count as active',
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="expiring"]').textContent.trim()`,
        ),
        '0',
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="sales-count"]').textContent.trim()`,
        ),
        '6',
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="sales-total"]').textContent.trim()`,
        ),
        '881,00 zł',
    );
    await evaluate(
        `(() => { for (const [id, value] of [['sales-from', '2000-01-01'], ['sales-to', '2000-01-02']]) { const input = document.getElementById(id); input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); } })()`,
    );
    await evaluate(`document.querySelector('button[type="submit"]').click()`);
    await until(
        `document.querySelector('[data-metric="sales-count"]')?.textContent.trim() === '0'`,
    );
    assert.equal(
        await evaluate(
            `document.querySelector('[data-metric="active"]').textContent.trim()`,
        ),
        '2',
    );
    assert.equal(
        await evaluate(`document.querySelectorAll('article').length`),
        7,
        'Recent orders must remain independent of sales date window',
    );
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );
    const dashboardShot = await call('Page.captureScreenshot', {
        captureBeyondViewport: true,
    });
    await writeFile(
        '/tmp/activefit-admin-dashboard-mobile.png',
        Buffer.from(dashboardShot.data, 'base64'),
    );
    // Publication must sanitize stored content and keep its confirmation boundary.
    await navigate('/admin/cms/regulamin/nowa-wersja');
    await until(`!!document.querySelector('[contenteditable="true"]')`);
    await evaluate(
        `(() => { const editor = document.querySelector('[contenteditable="true"]'); editor.innerHTML = '<h2>Regulamin regresyjny</h2><script>window.__cmsXss = true</script><a href="javascript:alert(1)">Treść testowa</a>'; editor.dispatchEvent(new Event('input', { bubbles: true })); })()`,
    );
    await evaluate(`document.querySelector('button[type="submit"]').click()`);
    await until(`!!document.querySelector('[role="dialog"]')`);
    await until(
        `document.querySelector('[role="dialog"]')?.contains(document.activeElement)`,
    );
    await call('Input.dispatchKeyEvent', {
        type: 'keyDown',
        key: 'Escape',
        code: 'Escape',
        windowsVirtualKeyCode: 27,
    });
    await call('Input.dispatchKeyEvent', {
        type: 'keyUp',
        key: 'Escape',
        code: 'Escape',
        windowsVirtualKeyCode: 27,
    });
    await until(`!document.querySelector('[role="dialog"]')`);
    await evaluate(`document.querySelector('button[type="submit"]').click()`);
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Potwierdź zapis');
    await until(
        `document.querySelector('h1')?.textContent.trim() === 'Regulamin'`,
    );
    await pageButton('Opublikuj');
    await until(`!!document.querySelector('[role="dialog"]')`);
    await dialogButton('Opublikuj regulamin');
    await until(`!document.querySelector('[role="dialog"]')`);
    await navigate('/regulamin');
    await until(`document.body.textContent.includes('Regulamin regresyjny')`);
    assert.equal(
        await evaluate(
            `!!document.querySelector('a[href^="javascript:"]') || window.__cmsXss === true`,
        ),
        false,
    );
    assert.equal(
        await evaluate(`document.body.textContent.includes('window.__cmsXss')`),
        false,
    );
    assert.equal(
        await evaluate('document.documentElement.scrollWidth <= innerWidth'),
        true,
    );

    // Update only the current profile; email changes are intentionally outside this synthetic flow.
    await evaluate(
        `fetch('/__checkout_test/login-customer', { method: 'POST' })`,
    );
    await navigate('/settings/profile');
    await until(`!!document.getElementById('first_name')`);
    await evaluate(
        `(() => { const field = document.getElementById('first_name'); field.value = 'AlicjaRegresja'; field.dispatchEvent(new Event('input', { bubbles: true })); })()`,
    );
    await evaluate(`document.querySelector('button[type="submit"]').click()`);
    await until(
        `document.body.textContent.includes('Dane profilu zostały zapisane.')`,
    );
    await navigate('/settings/profile');
    await until(
        `document.getElementById('first_name')?.value === 'AlicjaRegresja'`,
    );

    // Redeem the last remaining entry through the UI, then confirm it is unavailable.
    const receiptReference = (await state()).lastReference;
    await evaluate(`fetch('/__checkout_test/login-staff', { method: 'POST' })`);
    await navigate(`/recepcja/karnety/${receiptReference}`);
    await until(`document.body.textContent.includes('Odlicz jedno wejście')`);
    while ((await state()).latestMembership.balance > 0) {
        const previous = (await state()).latestMembership.balance;
        await pageButton('Odlicz jedno wejście');
        await until(`!!document.querySelector('[role="dialog"]')`);
        await dialogButton('Potwierdź');
        await until(`!document.querySelector('[role="dialog"]')`);
        assert.equal((await state()).latestMembership.balance, previous - 1);
    }
    assert.equal(
        await evaluate(
            `[...document.querySelectorAll('button')].some((button) => button.textContent.includes('Odlicz jedno wejście'))`,
        ),
        false,
    );
    const denied = await evaluate(
        `(async () => (await fetch('/admin/sprzedaz', { headers: { Accept: 'application/json' } })).status)()`,
    );
    assert.equal(denied, 403);

    // A separate administrator HTTP session suspends the still-authenticated browser employee.
    const administratorEntry = await fetch(base + '/');
    const entryCookies = administratorEntry.headers
        .getSetCookie()
        .map((cookie) => cookie.split(';')[0]);
    const entryCsrf = entryCookies.find((cookie) =>
        cookie.startsWith('XSRF-TOKEN='),
    );
    assert.ok(entryCsrf);
    const administrator = await fetch(base + '/__checkout_test/login-admin', {
        method: 'POST',
        redirect: 'manual',
        headers: {
            Cookie: entryCookies.join('; '),
            'X-XSRF-TOKEN': decodeURIComponent(
                entryCsrf.slice('XSRF-TOKEN='.length),
            ),
        },
    });
    assert.equal(
        administrator.status,
        200,
        'Administrator fixture login must succeed',
    );
    const administratorCookie = administrator.headers
        .getSetCookie()
        .map((cookie) => cookie.split(';')[0])
        .join('; ');
    const administratorPage = await fetch(base + '/admin/pracownicy', {
        headers: { Cookie: administratorCookie },
        redirect: 'manual',
    });
    assert.equal(
        administratorPage.status,
        200,
        'Separate administrator session must be authenticated',
    );
    const csrfCookie = administratorPage.headers
        .getSetCookie()
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
    assert.ok(csrfCookie, 'Administrator page must issue a CSRF cookie');
    const suspension = await fetch(
        base + '/admin/pracownicy/' + (await state()).staffId + '/dostep',
        {
            method: 'PATCH',
            redirect: 'manual',
            headers: {
                Cookie: administratorCookie,
                'X-XSRF-TOKEN': decodeURIComponent(
                    csrfCookie.split(';')[0].slice('XSRF-TOKEN='.length),
                ),
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ suspended: true, return_to: 'list' }),
        },
    );
    assert.equal(suspension.status, 302);
    assert.ok(
        suspension.headers.get('location')?.includes('/admin/pracownicy'),
        'Suspension must redirect to the employee index',
    );
    await navigate('/recepcja/karnety');
    await until(
        `document.body.textContent.includes('Nie możesz teraz korzystać z konta')`,
    );
    assert.equal(
        await evaluate(
            `document.body.textContent.includes('Nie możesz teraz korzystać z konta')`,
        ),
        true,
    );
    assert.deepEqual(errors, [], 'Unexpected browser JavaScript exceptions');
    console.log(
        'PASS: checkout, private PDF, timeout, admin delivery history, masked contacts, resend confirmation/cancel, mail failure, own order history, scheduled renewal, rejected payment retry, reception sale, separate activation/redemption, shared-phone staff lookup, own membership pending/scheduled/expired/empty states, protected PDF, admin sales filters, scoped audit/delivery links, administrator metrics, date window, sanitized CMS publication, profile update, final entry, existing-session suspension and desktop/mobile layouts.',
    );
} finally {
    ws?.close();
    chrome.kill('SIGTERM');
    await new Promise((resolve) => chrome.once('exit', resolve));
    await rm(profile, {
        recursive: true,
        force: true,
        maxRetries: 5,
        retryDelay: 100,
    });
}
