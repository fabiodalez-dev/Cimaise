const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/image-version-notice.js'), 'utf8');

// Runs the visitor script with a fake clock, timers and network.
function load({ pending, revision = 'a'.repeat(32), serverRevision = revision }) {
  let now = 0;
  const intervals = new Map();
  let serial = 0;
  const requests = [];
  const listeners = new Map();
  const image = { getAttribute: name => (name === 'src' ? '/media/7_sm.jpg' : '') };
  const document = {
    hidden: false,
    getElementById: () => null,
    querySelectorAll: () => [image],
    addEventListener: (name, fn) => listeners.set(name, fn),
    removeEventListener: name => listeners.delete(name),
    body: { appendChild() {} },
    createElement: () => ({ dataset: {}, setAttribute() {}, appendChild() {}, addEventListener() {} })
  };
  const context = {
    window: { cimaiseImageNotice: { pending, revision, endpoint: '/api/image-generation-revision', message: 'm', reload: 'r', dismiss: 'd' } },
    document,
    navigator: { onLine: true },
    location: { href: 'http://example.test/album/x', reload() {} },
    URL, AbortController, DOMParser: class { parseFromString() { return document; } },
    Date: { now: () => now },
    setTimeout: () => 0, clearTimeout() {},
    setInterval: (fn, delay) => { intervals.set(++serial, { fn, delay }); return serial; },
    clearInterval: id => intervals.delete(id),
    fetch: async url => {
      requests.push(url);
      return { ok: true, json: async () => ({ revision: serverRevision }), headers: { get: () => 'text/html' }, text: async () => '' };
    }
  };
  vm.runInNewContext(source, context);
  const settle = async () => { for (let i = 0; i < 10; i++) await Promise.resolve(); };
  return {
    requests, intervals, listeners, settle,
    showUpdate: context.window.cimaiseShowUpdate,
    advance: async ms => { now += ms; for (const { fn } of [...intervals.values()]) fn(); await settle(); }
  };
}

test('no queued images: the page never polls, the shared update pill still exists', async () => {
  const page = load({ pending: false });
  await page.settle();
  assert.equal(typeof page.showUpdate, 'function');
  assert.equal(page.intervals.size, 0);
  assert.deepEqual(page.requests, []);
});

test('queued images: the page polls, then stops after ten minutes', async () => {
  const page = load({ pending: true });
  await page.settle();
  assert.equal(page.requests.length, 1);
  assert.equal(page.intervals.size, 1);
  assert.equal([...page.intervals.values()][0].delay, 30000);

  await page.advance(30000);
  assert.equal(page.requests.length, 2);

  await page.advance(10 * 60 * 1000);
  const afterDeadline = page.requests.length;
  assert.equal(page.intervals.size, 0, 'the interval is cleared once the watch window has passed');
  assert.equal(page.listeners.has('visibilitychange'), false);
  await page.advance(30000);
  assert.equal(page.requests.length, afterDeadline);
});
