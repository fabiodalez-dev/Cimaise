import { createAbortError } from './AbortController.js';
/**
 * Return a Promise that resolves after `ms` milliseconds.
 */
export default function delay(ms, opts) {
    return new Promise((resolve, reject) => {
        if (opts?.signal?.aborted) {
            return reject(createAbortError());
        }
        const timeout = setTimeout(() => {
            cleanup();
            resolve();
        }, ms);
        function onabort() {
            clearTimeout(timeout);
            cleanup();
            reject(createAbortError());
        }
        opts?.signal?.addEventListener('abort', onabort);
        function cleanup() {
            opts?.signal?.removeEventListener('abort', onabort);
        }
        return undefined;
    });
}
