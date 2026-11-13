<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Re-verifies the admin identity stored in the session against the users
 * table. AuthMiddleware already does this for /admin; frontend controllers and
 * the /media fast path never run it, so BaseController::isAdmin() asks here.
 *
 * Sessions are also bound to a fingerprint of the account's password hash:
 * a password change or reset invalidates every other session of that account.
 */
final class AdminSession
{
    public const FINGERPRINT_KEY = 'admin_pw_fp';

    private static ?Database $db = null;

    /** @var array<string, bool> per-request memo */
    private static array $memo = [];

    public static function setDatabase(?Database $db): void
    {
        self::$db = $db;
        self::$memo = [];
    }

    public static function fingerprint(string $passwordHash): string
    {
        return substr(hash('sha256', 'cimaise-admin-session|' . $passwordHash), 0, 32);
    }

    /** Bind the CURRENT session to the account's current password hash. */
    public static function bind(string $passwordHash): void
    {
        $_SESSION[self::FINGERPRINT_KEY] = self::fingerprint($passwordHash);
        self::$memo = [];
    }

    /**
     * Whether a users row still authorises this session as admin.
     *
     * @param array<string, mixed>|false|null $user
     */
    public static function rowAuthorises(array|false|null $user): bool
    {
        if (!is_array($user) || empty($user['is_active']) || ($user['role'] ?? '') !== 'admin') {
            return false;
        }
        $bound = $_SESSION[self::FINGERPRINT_KEY] ?? null;
        return is_string($bound) && hash_equals(self::fingerprint((string) ($user['password_hash'] ?? '')), $bound);
    }

    /**
     * True when $_SESSION['admin_id'] still names an active admin whose
     * password has not changed since this session was bound. Without a
     * registered database (CLI tools, isolated unit tests) only the session
     * flag can be consulted.
     */
    public static function isCurrent(): bool
    {
        $id = (int) ($_SESSION['admin_id'] ?? 0);
        if ($id <= 0) {
            return false;
        }
        if (self::$db === null) {
            return true;
        }
        $key = $id . '|' . (string) ($_SESSION[self::FINGERPRINT_KEY] ?? '');
        if (!array_key_exists($key, self::$memo)) {
            try {
                $stmt = self::$db->pdo()->prepare('SELECT is_active, role, password_hash FROM users WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $id]);
                $user = $stmt->fetch();
                $stmt->closeCursor();
                self::$memo[$key] = self::rowAuthorises($user);
            } catch (\Throwable) {
                self::$memo[$key] = false; // fail closed
            }
        }
        return self::$memo[$key];
    }
}
