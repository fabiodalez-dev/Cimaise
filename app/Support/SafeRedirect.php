<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalises a user-influenced redirect target into a same-origin path.
 *
 * Browsers are lenient with Location values: they drop ASCII TAB/CR/LF and
 * other control characters and treat "\" like "/" in special-scheme URLs, so
 * "/\evil.example" or "/\t/evil.example" become the protocol-relative
 * "//evil.example". Collapsing leading "/" alone is not enough; the input is
 * stripped of control characters and backslashes are folded into slashes
 * BEFORE the leading slashes are collapsed.
 */
final class SafeRedirect
{
    public static function path(string $target): string
    {
        $target = preg_replace('/[\x00-\x1F\x7F]/', '', $target) ?? '';
        $target = str_replace('\\', '/', $target);
        // Absolute URLs (scheme://...) are never followed.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $target)) {
            return '/';
        }
        // Exactly one leading slash: never protocol-relative.
        return '/' . ltrim($target, '/');
    }
}
