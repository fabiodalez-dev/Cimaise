# Release patches (optional, signed)

Drop a `pre-update-patch.php` and/or `post-install-patch.php` here for a
release that needs a one-off fix the normal package + migrations can't express
(an in-place file edit, a stale-file deletion, or an ad-hoc SQL statement).

On `git tag vX.Y.Z`, the release workflow signs each present file with the
project's Ed25519 key (`PLUGIN_SIGNING_KEY` secret) and attaches it — plus its
`.sig` — as a release asset. The updater downloads the asset, **verifies the
Ed25519 signature before executing**, and runs nothing if the signature is
absent or invalid (fail-closed). When no signing key is configured the whole
mechanism is disabled.

This directory is **not** shipped inside the `cimaise-*.zip` package (the
`.rsync-filter` catch-all excludes it); the patches travel only as standalone,
signed release assets.

## File shape

Each file must `return` an array. Recognized keys:

```php
<?php
return [
    // Only apply when upgrading FROM one of these versions (pre-update only).
    'target_versions' => ['1.4.0'],

    // File search-replace. `search` must occur exactly once; the path must
    // stay inside the app root.
    'patches' => [
        ['file' => 'app/Foo.php', 'search' => 'old', 'replace' => 'new', 'description' => '...'],
    ],

    // post-install-patch.php only — delete stale files (protected paths refused).
    'cleanup' => ['public/assets/old-thing.js'],

    // post-install-patch.php only — ad-hoc SQL (a blocklist refuses
    // catastrophic statements; idempotent errors are tolerated).
    'sql' => ["UPDATE settings SET value = '1' WHERE \"key\" = 'some_flag'"],
];
```

Remove the files after the release ships; they are per-release, not cumulative.
