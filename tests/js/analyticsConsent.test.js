import assert from 'node:assert/strict';
import test from 'node:test';

const moduleUrl = new URL('../../resources/js/Support/analyticsConsent.js', import.meta.url);

function browserEnvironment({
    href = 'https://tilbudsfinder.dk/tilbud?q=alice%40example.com&page=2',
    storedConsent = null,
} = {}) {
    const storage = new Map();
    const scripts = [];
    const cookieWrites = [];
    const location = new URL(href);

    if (storedConsent) {
        storage.set('tilbudsfinder_cookie_consent', JSON.stringify(storedConsent));
    }

    globalThis.window = {
        location,
        localStorage: {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, value),
        },
    };

    globalThis.document = {
        head: {
            appendChild: (script) => scripts.push(script),
        },
        querySelector: (selector) => (
            selector === 'meta[name="google-analytics-id"]'
                ? { content: 'G-TJ3MBH96Q0' }
                : null
        ),
        createElement: () => ({}),
    };

    Object.defineProperty(globalThis.document, 'cookie', {
        set: (value) => cookieWrites.push(value),
    });

    return { cookieWrites, scripts, storage };
}

async function analyticsModule() {
    return import(`${moduleUrl.href}?test=${crypto.randomUUID()}`);
}

function dataLayerCalls() {
    return window.dataLayer.map((call) => Array.from(call));
}

test('accepting statistics loads GA once and sends a redacted page view', async () => {
    const environment = browserEnvironment();
    const analytics = await analyticsModule();

    analytics.setStatisticsConsent(true);

    assert.equal(environment.scripts.length, 1);
    assert.equal(environment.scripts[0].src, 'https://www.googletagmanager.com/gtag/js?id=G-TJ3MBH96Q0');

    const calls = dataLayerCalls();
    assert.deepEqual(calls[1], [
        'config',
        'G-TJ3MBH96Q0',
        {
            cookie_expires: 31_536_000,
            cookie_update: false,
            send_page_view: false,
        },
    ]);
    assert.deepEqual(calls[2], [
        'event',
        'page_view',
        { page_location: 'https://tilbudsfinder.dk/tilbud' },
    ]);
    assert.equal(JSON.parse(environment.storage.get('tilbudsfinder_cookie_consent')).version, 2);
});

test('page views are deduplicated and query-only changes are ignored', async () => {
    browserEnvironment();
    const analytics = await analyticsModule();

    analytics.setStatisticsConsent(true);
    analytics.trackPageView();
    window.location = new URL('https://tilbudsfinder.dk/tilbud?q=sm%C3%B8r');
    analytics.trackPageView();
    window.location = new URL('https://tilbudsfinder.dk/butikker?q=ignored');
    analytics.trackPageView();

    const pageViews = dataLayerCalls().filter(([command, event]) => command === 'event' && event === 'page_view');

    assert.deepEqual(pageViews, [
        ['event', 'page_view', { page_location: 'https://tilbudsfinder.dk/tilbud' }],
        ['event', 'page_view', { page_location: 'https://tilbudsfinder.dk/butikker' }],
    ]);
});

test('expired consent disables analytics and removes its cookies', async () => {
    const environment = browserEnvironment({
        storedConsent: {
            version: 2,
            statistics: true,
            decidedAt: new Date(Date.now() - 366 * 24 * 60 * 60 * 1000).toISOString(),
        },
    });
    const analytics = await analyticsModule();

    analytics.initializeAnalyticsConsent();

    assert.equal(environment.scripts.length, 0);
    assert.equal(window['ga-disable-G-TJ3MBH96Q0'], true);
    assert.ok(environment.cookieWrites.some((cookie) => cookie.startsWith('_ga=')));
    assert.ok(environment.cookieWrites.some((cookie) => cookie.startsWith('_ga_TJ3MBH96Q0=')));
    assert.equal(analytics.getStatisticsConsent(), null);
});

test('rejecting statistics persists the decision without loading GA', async () => {
    const environment = browserEnvironment();
    const analytics = await analyticsModule();

    analytics.setStatisticsConsent(false);

    const consent = JSON.parse(environment.storage.get('tilbudsfinder_cookie_consent'));

    assert.equal(environment.scripts.length, 0);
    assert.equal(window['ga-disable-G-TJ3MBH96Q0'], true);
    assert.equal(consent.version, 2);
    assert.equal(consent.statistics, false);
    assert.ok(Number.isFinite(Date.parse(consent.decidedAt)));
});
