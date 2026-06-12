/**
 * Base path helpers for subfolder installs.
 *
 * The server exposes the installation base path on window.basePath
 * ('' on root installs, '/portfolio' on subfolder installs).
 */

/**
 * Strip trailing slashes so '' and '/' both collapse to '' and
 * '/portfolio/' becomes '/portfolio' — prevents double-slash (or
 * protocol-relative '//...') URLs when concatenating routes.
 *
 * @param {string} path
 * @returns {string}
 */
export function normalizeBasePath(path) {
  return typeof path === 'string' ? path.replace(/\/+$/, '') : '';
}

/**
 * The installation base path from window.basePath, normalized.
 *
 * @returns {string} '' on root installs, '/sub' on subfolder installs
 */
export function getBasePath() {
  return normalizeBasePath(typeof window !== 'undefined' ? window.basePath : '');
}
