# Signing keys

This directory holds the **public** Ed25519 key the app trusts for everything
the project signs (see `App\Support\PluginSignature`):

- plugin archives, before installation (review finding H3);
- release migration patches (`pre-update-patch.php` / `post-install-patch.php`);
- the self-updater's release package `cimaise-vX.Y.Z.zip` (`.zip.sig`), mandatory
  from `Updater::PACKAGE_SIGNATURE_REQUIRED_FROM` on — see `docs/updater.md`.

Each signature is made over a message that names its purpose (`plugin`,
`patch`, `package`), so a plugin signature can never be used as a package or
patch signature. Rotating this key (`plugin:keygen --force`) means every
install must receive the new public key through an update signed with the OLD
key first; otherwise they refuse all further updates.

## Files

- `plugin-signing.pub` — base64 of the 32-byte Ed25519 **public** key. Safe to
  commit. When present, plugin uploads MUST carry a valid signature or they are
  rejected (`PluginsController::upload`). When absent, signature checks are
  disabled and only the advisory static scan runs.

## The private key is NEVER stored here (or anywhere in the repo)

Generate a keypair once:

```bash
php bin/console plugin:keygen
```

This writes `plugin-signing.pub` here and prints the **secret** key (base64) to
stdout. Store the secret key somewhere safe:

- locally: an env var `PLUGIN_SIGNING_KEY` (e.g. in your shell profile, NOT `.env`
  if `.env` is ever shipped), or a file outside the repo;
- in CI: a GitHub Actions secret named `PLUGIN_SIGNING_KEY`, used by the
  `sign-release` job of `.github/workflows/release.yml` and by
  `.github/workflows/sign-plugin.yml`. Both run in the `release-signing`
  environment: put the secret there and restrict it to `v*` tags.

## Signing a plugin archive

```bash
# uses $PLUGIN_SIGNING_KEY, writes my-plugin.zip.sig (base64 detached signature)
php bin/console plugin:sign path/to/my-plugin.zip
```

Distribute `my-plugin.zip` together with `my-plugin.zip.sig`. At install the
admin uploads the ZIP and supplies the signature (a `signature` file field or
the `X-Plugin-Signature` header); the server verifies it against the public key.

## Signing a release package or patch by hand

CI does this automatically; locally:

```bash
php bin/console plugin:sign cimaise-v1.5.0.zip --type package --release 1.5.0
```

```bash
php bin/console plugin:sign post-install-patch.php --type patch --release 1.5.0
```
