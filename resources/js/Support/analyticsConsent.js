const consentStorageKey = 'tilbudsfinder_cookie_consent';
const consentVersion = 2;
const consentLifetimeMs = 365 * 24 * 60 * 60 * 1000;
const consentLifetimeSeconds = consentLifetimeMs / 1000;

let analyticsLoaded = false;
let lastTrackedLocation = null;

function measurementId() {
    return document.querySelector('meta[name="google-analytics-id"]')?.content || null;
}

function readStoredConsent() {
    try {
        const consent = JSON.parse(window.localStorage.getItem(consentStorageKey));
        const decidedAt = Date.parse(consent?.decidedAt);

        if (
            consent?.version !== consentVersion
            || typeof consent.statistics !== 'boolean'
            || !Number.isFinite(decidedAt)
            || Date.now() - decidedAt >= consentLifetimeMs
        ) {
            return null;
        }

        return consent;
    } catch {
        return null;
    }
}

function writeStoredConsent(statistics) {
    try {
        window.localStorage.setItem(
            consentStorageKey,
            JSON.stringify({
                version: consentVersion,
                statistics,
                decidedAt: new Date().toISOString(),
            }),
        );
    } catch {}
}

function deleteAnalyticsCookies(id) {
    const cookieNames = ['_ga', `_ga_${id.replace(/^G-/, '')}`];
    const hostParts = window.location.hostname.split('.');
    const domains = ['', window.location.hostname, `.${window.location.hostname}`];

    if (hostParts.length > 1) {
        domains.push(`.${hostParts.slice(-2).join('.')}`);
    }

    for (const name of cookieNames) {
        for (const domain of [...new Set(domains)]) {
            const domainAttribute = domain ? `; domain=${domain}` : '';
            document.cookie = `${name}=; Max-Age=0; path=/${domainAttribute}; SameSite=Lax`;
        }
    }
}

function loadGoogleAnalytics(id) {
    if (analyticsLoaded) {
        window[`ga-disable-${id}`] = false;
        trackPageView();
        return;
    }

    analyticsLoaded = true;
    window[`ga-disable-${id}`] = false;
    window.dataLayer = window.dataLayer || [];
    window.gtag = function gtag() {
        window.dataLayer.push(arguments);
    };

    window.gtag('js', new Date());
    window.gtag('config', id, {
        cookie_expires: consentLifetimeSeconds,
        cookie_update: false,
        send_page_view: false,
    });

    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    document.head.appendChild(script);
    trackPageView();
}

function disableGoogleAnalytics(id) {
    window[`ga-disable-${id}`] = true;
    lastTrackedLocation = null;
    deleteAnalyticsCookies(id);
}

function applyStatisticsConsent(statistics) {
    const id = measurementId();

    if (!id) {
        return;
    }

    if (statistics) {
        loadGoogleAnalytics(id);
    } else {
        disableGoogleAnalytics(id);
    }
}

export function getStatisticsConsent() {
    return readStoredConsent()?.statistics ?? null;
}

export function isAnalyticsConfigured() {
    return measurementId() !== null;
}

export function setStatisticsConsent(statistics) {
    writeStoredConsent(statistics);
    applyStatisticsConsent(statistics);
}

export function initializeAnalyticsConsent() {
    const consent = readStoredConsent();
    applyStatisticsConsent(consent?.statistics ?? false);
}

export function trackPageView() {
    const id = measurementId();
    const pageLocation = `${window.location.origin}${window.location.pathname}`;

    if (
        !id
        || readStoredConsent()?.statistics !== true
        || typeof window.gtag !== 'function'
        || pageLocation === lastTrackedLocation
    ) {
        return;
    }

    lastTrackedLocation = pageLocation;
    window.gtag('event', 'page_view', {
        page_location: pageLocation,
    });
}
