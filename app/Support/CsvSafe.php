<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Neutralise spreadsheet formula injection in CSV exports: a cell that starts
 * with = + - @ TAB or CR is evaluated as a formula by Excel/LibreOffice/Sheets,
 * so it is prefixed with a single quote and exported as inert text.
 */
final class CsvSafe
{
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * @param array<int|string, mixed> $row
     * @return array<int|string, mixed>
     */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
