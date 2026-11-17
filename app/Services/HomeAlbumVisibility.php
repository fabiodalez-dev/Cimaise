<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

final class HomeAlbumVisibility
{
    public function __construct(private readonly Database $db)
    {
    }

    // All homepage queries use a as the album alias. Gallery queries do not use this scope.
    // $includeProtected keeps password-protected albums (the admin's view of
    // the album list); albums hidden from the homepage stay out for everyone.
    public function eligibleSql(bool $includeProtected = false): string
    {
        $key = $this->db->isSqlite()
            ? "'album.home_hidden.' || a.id"
            : "CONCAT('album.home_hidden.', a.id)";
        $protected = $includeProtected ? '' : " AND (a.password_hash IS NULL OR a.password_hash = '')";

        return $protected . " AND NOT EXISTS (SELECT 1 FROM settings hv WHERE hv.`key` = {$key})";
    }

    public function isHidden(int $albumId): bool
    {
        return $this->db->query('SELECT 1 FROM settings WHERE `key` = ?', [$this->key($albumId)])->fetchColumn() !== false;
    }

    public function save(int $albumId, bool $hidden): void
    {
        if ($hidden) {
            $insert = $this->db->insertIgnoreKeyword();
            $now = $this->db->nowExpression();
            $this->db->execute("$insert INTO settings (`key`, `value`, `type`, updated_at)
                VALUES (?, 'true', 'boolean', $now)", [$this->key($albumId)]);
        } else {
            $this->db->execute('DELETE FROM settings WHERE `key` = ?', [$this->key($albumId)]);
        }
    }

    private function key(int $albumId): string
    {
        return 'album.home_hidden.' . $albumId;
    }
}
