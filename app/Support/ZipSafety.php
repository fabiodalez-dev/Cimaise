<?php

declare(strict_types=1);

namespace App\Support;

use ZipArchive;

/**
 * Pre-extraction guard against zip-slip.
 *
 * ZipArchive::extractTo() offers no per-entry safety: it writes symlink
 * entries as real symlinks and then follows them for later entries, so a
 * crafted archive ("evil -> /etc" followed by "evil/passwd") writes outside
 * the extraction directory. Absolute paths and ".." segments are the classic
 * traversal vector. Every entry is checked BEFORE anything is extracted, and
 * a single unsafe entry rejects the whole archive.
 */
final class ZipSafety
{
    /** Unix file-type bits of the external attributes (S_IFMT / S_IFLNK). */
    private const S_IFMT = 0xF000;
    private const S_IFLNK = 0xA000;

    /**
     * "Made by" host systems that store Unix mode bits in the high 16 bits of
     * the external attributes: Unix (3) and OS X/Darwin (19). Archives built on
     * macOS carry 19, and the uploader controls this byte anyway.
     */
    private const UNIX_LIKE_OPSYS = [ZipArchive::OPSYS_UNIX, 19];

    /**
     * True when the (already opened) archive contains at least one entry that
     * could escape the extraction directory: an absolute path, a ".." segment,
     * a NUL byte or a symlink.
     */
    public static function hasUnsafeEntries(ZipArchive $zip): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '') {
                return true;
            }
            if (!self::isSafeEntryName($name)) {
                return true;
            }
            if (self::isSymlinkEntry($zip, $i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reject absolute paths (Unix or Windows), path traversal and NUL bytes.
     * Backslashes are treated as separators too so "..\\" cannot slip past on
     * a Windows host.
     */
    public static function isSafeEntryName(string $name): bool
    {
        if (str_contains($name, "\0")) {
            return false;
        }
        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return false;
        }
        if (preg_match('#^[A-Za-z]:#', $name) === 1) {
            return false;
        }

        return preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $name) !== 1;
    }

    /** Symlink entries carry S_IFLNK in the high 16 bits of the Unix external attributes. */
    private static function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return in_array($opsys, self::UNIX_LIKE_OPSYS, true)
            && ((($attr >> 16) & self::S_IFMT) === self::S_IFLNK);
    }
}
