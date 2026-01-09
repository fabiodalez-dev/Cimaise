/**
 * Home Infinite Gallery Loader
 *
 * DISABLED: Background loading and DOM replacement has been disabled because:
 * 1. innerHTML replacement destroys existing DOM causing visual flicker
 * 2. Images need to re-render even if cached, causing photos to disappear/reappear
 * 3. The initial SSR content already has 2-5 repetitions (96-240 items) which
 *    provides sufficient content for seamless infinite scroll animation
 *
 * The Twig template (_infinite_gallery.twig) handles all initial rendering with
 * proper repetition for the CSS-based infinite scroll effect.
 *
 * Future improvements could:
 * - Append new items instead of replacing entire DOM
 * - Use DocumentFragment for smoother insertion
 * - Apply CSS transitions when swapping content
 * - Use IntersectionObserver to load more only when needed
 */
(function () {
    'use strict';
    // No-op: background loading disabled to prevent flicker
})();
