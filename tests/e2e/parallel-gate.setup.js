// @ts-check
// No-op setup of the parallel suite. Its teardown project ("settings" in
// playwright.config.js) runs the settings-writing specs once every parallel
// spec has finished.

import { test } from '@playwright/test';

test('parallel suite gate', () => {});
