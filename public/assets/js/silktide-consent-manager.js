/*!
 * Minimal stub for Silktide Consent Manager
 * Reason: the original @silktide/cookie-consent-manager package is no longer
 * distributed via npm/CDN. This stub provides the minimum surface area the
 * cookie-banner.twig partial expects (silktideCookieBannerManager +
 * window.CookieControl) so the page does not 404 on the script tag and the
 * custom-js-loader's consent check can resolve.
 *
 * Behavior: passive — does NOT render a UI banner. Consent defaults to true
 * for required cookies; analytics/marketing default to false unless the
 * consumer JS explicitly grants them via CookieControl.setCategoryConsent().
 *
 * To replace with a real consent manager: drop a full implementation that
 * exposes the same two globals here, or modify cookie-banner.twig to load
 * a different library entirely.
 */
(function (global) {
    'use strict';

    if (global.silktideCookieBannerManager) {
        return; // already loaded
    }

    var STORAGE_KEY = 'silktide_consent_v1';
    var consent = { essential: true };

    try {
        var raw = global.localStorage && global.localStorage.getItem(STORAGE_KEY);
        if (raw) {
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                consent = Object.assign({ essential: true }, parsed);
            }
        }
    } catch (e) { /* storage unavailable — keep defaults */ }

    function persist() {
        try {
            global.localStorage && global.localStorage.setItem(STORAGE_KEY, JSON.stringify(consent));
        } catch (e) { /* ignore */ }
    }

    function fireChanged() {
        try {
            global.dispatchEvent(new CustomEvent('silktideConsentChanged', { detail: Object.assign({}, consent) }));
        } catch (e) { /* old browser */ }
    }

    global.silktideCookieBannerManager = {
        // No-op: the real library renders a UI here; the stub just records that
        // the consumer attempted to configure the banner.
        updateCookieBannerConfig: function (_config) {
            global.silktideCookieBannerManager._lastConfig = _config || null;
            return true;
        },
        // Helpers for the page to mutate consent programmatically.
        _accept: function (categoryId) {
            consent[categoryId] = true;
            persist();
            fireChanged();
        },
        _reject: function (categoryId) {
            consent[categoryId] = false;
            persist();
            fireChanged();
        }
    };

    global.CookieControl = {
        getCategoryConsent: function (categoryId) {
            if (categoryId === 'essential') return true;
            return consent[categoryId] === true;
        },
        setCategoryConsent: function (categoryId, value) {
            consent[categoryId] = !!value;
            persist();
            fireChanged();
        }
    };
})(typeof window !== 'undefined' ? window : this);
