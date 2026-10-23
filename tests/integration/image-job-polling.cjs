const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

test('idle polling backs off, wakes for uploads and visibility, and never overlaps', async () => {
  const events = new EventTarget();
  const document = new EventTarget();
  const timers = new Map();
  const panel = { hidden: true, querySelector: () => ({ removeAttribute() {} }) };
  document.hidden = false;
  document.getElementById = () => panel;
  let serial = 0;
  let requests = 0;
  let jobs = [];
  let pending;
  const window = {
    adminTf: (_, params, fallback) => fallback,
    setTimeout: (callback, delay) => { timers.set(++serial, { callback, delay }); return serial; },
    clearTimeout: id => timers.delete(id),
    addEventListener: (...args) => events.addEventListener(...args),
    dispatchEvent: event => events.dispatchEvent(event)
  };
  const context = {
    window, document, navigator: { onLine: true }, AbortController,
    sessionStorage: { getItem: () => null, setItem() {} },
    CustomEvent: class extends Event { constructor(name, options) { super(name); this.detail = options.detail; } },
    fetch: async () => {
      requests++;
      if (pending) await pending;
      return { ok: true, status: 200, json: async () => ({ jobs }) };
    }
  };
  const settle = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
  const scheduledDelay = () => { assert.equal(timers.size, 1); return [...timers.values()][0].delay; };
  const tick = async () => {
    const [id, { callback }] = [...timers][0];
    timers.delete(id);
    callback();
    await settle();
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/assets/image-job-progress.js'), 'utf8'), context);
  await settle();
  assert.equal(requests, 1);
  assert.equal(scheduledDelay(), 30000);
  jobs = [{ id: 1, pending: true, state: 'processing', total: 15, completed: 1 }];
  events.dispatchEvent(new Event('cimaise:upload-activity'));
  await settle();
  assert.equal(requests, 2, 'Upload starts an immediate check');
  assert.equal(scheduledDelay(), 2000);
  document.hidden = true;
  await tick();
  assert.equal(requests, 2, 'Hidden tabs make no requests');
  assert.equal(scheduledDelay(), 30000);
  document.hidden = false;
  let release;
  pending = new Promise(resolve => { release = resolve; });
  document.dispatchEvent(new Event('visibilitychange'));
  events.dispatchEvent(new Event('online'));
  events.dispatchEvent(new Event('cimaise:upload-activity'));
  assert.equal(requests, 3, 'Wake events do not overlap an in-flight check');
  release();
  pending = null;
  await settle();
  assert.equal(scheduledDelay(), 0, 'Coalesced wake is scheduled after completion');
  await tick();
  assert.equal(requests, 4);
  assert.equal(scheduledDelay(), 2000);
});
