<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The application secret (SESSION_SECRET), or null when it is missing or is
 * still the placeholder shipped in .env.example. Anything keyed on it (cookie
 * HMACs, SecretBox, analytics salt) must treat the placeholder as "no secret":
 * a key derived from a public value protects nothing.
 */
final class AppSecret
{
    public const PLACEHOLDER = 'your-session-secret-key-here';

    public static function session(): ?string
    {
        $secret = $_ENV['SESSION_SECRET'] ?? $_SERVER['SESSION_SECRET'] ?? getenv('SESSION_SECRET');
        if (!is_string($secret)) {
            return null;
        }
        $secret = trim($secret);
        if ($secret === '' || $secret === self::PLACEHOLDER) {
            return null;
        }
        return $secret;
    }
}
