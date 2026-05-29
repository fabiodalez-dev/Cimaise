# Plugin signing keys

This directory holds the **public** Ed25519 key used to verify plugin archives
before installation (see `App\Support\PluginSignature` and review finding H3).

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
- in CI: a GitHub Actions secret named `PLUGIN_SIGNING_KEY` (used by
  `.github/workflows/sign-plugin.yml`).

## Signing a plugin archive

```bash
# uses $PLUGIN_SIGNING_KEY, writes my-plugin.zip.sig (base64 detached signature)
php bin/console plugin:sign path/to/my-plugin.zip
```

Distribute `my-plugin.zip` together with `my-plugin.zip.sig`. At install the
admin uploads the ZIP and supplies the signature (a `signature` file field or
the `X-Plugin-Signature` header); the server verifies it against the public key.
