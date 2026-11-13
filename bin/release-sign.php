<?php

/**
 * Release-signing helper for CI (.github/workflows/release.yml, sign-plugin.yml).
 *
 * Deliberately dependency-free: it loads ONLY app/Support/PluginSignature.php,
 * never vendor/autoload.php. The signing job therefore never runs Composer or
 * npm code while the secret key is in its environment — a compromised
 * third-party package cannot read PLUGIN_SIGNING_KEY.
 *
 * The secret key is read from the PLUGIN_SIGNING_KEY environment variable only
 * (never from argv, which is visible in the process list).
 *
 * Usage:
 *   php bin/release-sign.php package <cimaise-vX.Y.Z.zip> <X.Y.Z>   writes <zip>.sig
 *   php bin/release-sign.php patch   <file.php> <X.Y.Z>             writes <file>.sig (bound to basename)
 *   php bin/release-sign.php plugin  <plugin.zip>                   writes <zip>.sig
 *   php bin/release-sign.php verify-package <zip> <X.Y.Z> [<sig>]  checks against the committed public key
 *
 * Not shipped in the release package (.rsync-filter keeps only bin/console).
 */

declare(strict_types=1);

require __DIR__ . '/../app/Support/PluginSignature.php';

use App\Support\PluginSignature;

function release_sign_fail(string $message): never
{
    fwrite(STDERR, 'release-sign: ' . $message . PHP_EOL);
    exit(1);
}

function release_sign_read(string $path): string
{
    if (!is_file($path)) {
        release_sign_fail('file not found: ' . $path);
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        release_sign_fail('cannot read ' . $path);
    }
    return $bytes;
}

function release_sign_key(): string
{
    $key = getenv('PLUGIN_SIGNING_KEY');
    if (!is_string($key) || trim($key) === '') {
        release_sign_fail('PLUGIN_SIGNING_KEY is not set');
    }
    return trim($key);
}

function release_sign_write(string $path, string $signature): void
{
    if (file_put_contents($path, $signature . "\n") === false) {
        release_sign_fail('cannot write ' . $path);
    }
    fwrite(STDOUT, 'Signed: ' . $path . PHP_EOL);
}

$args = array_slice($_SERVER['argv'] ?? [], 1);
$mode = $args[0] ?? '';

try {
    switch ($mode) {
        case 'package':
            [$zip, $version] = [$args[1] ?? '', $args[2] ?? ''];
            if ($zip === '' || $version === '') {
                release_sign_fail('usage: package <zip> <version>');
            }
            $sha = hash('sha256', release_sign_read($zip));
            release_sign_write($zip . '.sig', PluginSignature::signPackage($version, $sha, release_sign_key()));
            // Self-check: the signature must verify against the COMMITTED public
            // key, otherwise every install would refuse this release.
            $sig = trim(release_sign_read($zip . '.sig'));
            if (!PluginSignature::verifyPackage($version, $sha, $sig)) {
                release_sign_fail('signature does not verify against resources/keys/plugin-signing.pub — '
                    . 'PLUGIN_SIGNING_KEY does not match the committed public key');
            }
            break;

        case 'patch':
            [$file, $version] = [$args[1] ?? '', $args[2] ?? ''];
            if ($file === '' || $version === '') {
                release_sign_fail('usage: patch <file> <version>');
            }
            $content = release_sign_read($file);
            release_sign_write($file . '.sig', PluginSignature::signPatch(basename($file), $version, $content, release_sign_key()));
            break;

        case 'plugin':
            $zip = $args[1] ?? '';
            if ($zip === '') {
                release_sign_fail('usage: plugin <zip>');
            }
            release_sign_write($zip . '.sig', PluginSignature::signPlugin(release_sign_read($zip), release_sign_key()));
            break;

        case 'verify-package':
            [$zip, $version] = [$args[1] ?? '', $args[2] ?? ''];
            $sigPath = $args[3] ?? ($zip . '.sig');
            if ($zip === '' || $version === '') {
                release_sign_fail('usage: verify-package <zip> <version> [<sig>]');
            }
            if (!PluginSignature::isEnabled()) {
                release_sign_fail('no public key configured (resources/keys/plugin-signing.pub)');
            }
            $sha = hash('sha256', release_sign_read($zip));
            if (!PluginSignature::verifyPackage($version, $sha, release_sign_read($sigPath))) {
                release_sign_fail('INVALID package signature for ' . basename($zip));
            }
            fwrite(STDOUT, 'Package signature OK: ' . basename($zip) . ' (' . $version . ', sha256 ' . $sha . ')' . PHP_EOL);
            break;

        case 'check-key':
            // Does PLUGIN_SIGNING_KEY match the committed public key? Signs a
            // throwaway package message (version 0.0.0, sha256 of a fixed
            // string) and verifies it. Prints nothing secret.
            if (!PluginSignature::isEnabled()) {
                release_sign_fail('no public key configured (resources/keys/plugin-signing.pub)');
            }
            $sha = hash('sha256', 'cimaise signing key check');
            $sig = PluginSignature::signPackage('0.0.0', $sha, release_sign_key());
            if (!PluginSignature::verifyPackage('0.0.0', $sha, $sig)) {
                release_sign_fail('PLUGIN_SIGNING_KEY does NOT match resources/keys/plugin-signing.pub');
            }
            fwrite(STDOUT, 'PLUGIN_SIGNING_KEY matches resources/keys/plugin-signing.pub' . PHP_EOL);
            break;

        default:
            release_sign_fail('unknown mode "' . $mode . '" (package | patch | plugin | verify-package | check-key)');
    }
} catch (\Throwable $e) {
    release_sign_fail($e->getMessage());
}
