<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Helper class for consistent date formatting across the application.
 *
 * Dates are always stored in ISO format (Y-m-d) in the database for SQL compatibility.
 * This class handles formatting dates for display based on user settings.
 */
class DateHelper
{
    public const FORMAT_ISO = 'Y-m-d';           // 2025-01-15
    public const FORMAT_EU = 'd-m-Y';            // 15-01-2025
    public const FORMAT_ISO_TIME = 'Y-m-d H:i';  // 2025-01-15 14:30
    public const FORMAT_EU_TIME = 'd-m-Y H:i';   // 15-01-2025 14:30

    private static ?string $displayFormat = null;

    /**
     * Set the display format for dates
     */
    public static function setDisplayFormat(string $format): void
    {
        if (!in_array($format, [self::FORMAT_ISO, self::FORMAT_EU], true)) {
            $format = self::FORMAT_ISO;
        }
        self::$displayFormat = $format;
    }

    /**
     * Get the current display format
     */
    public static function getDisplayFormat(): string
    {
        return self::$displayFormat ?? self::FORMAT_ISO;
    }

    /**
     * Format a date for display
     *
     * @param string|null $date Date in Y-m-d format (from database)
     * @param bool $withTime Include time component
     * @return string|null Formatted date or null if input is null/empty
     */
    public static function format(?string $date, bool $withTime = false): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        try {
            $datetime = new \DateTime($date);
            $format = self::getPhpFormat($withTime);
            return $datetime->format($format);
        } catch (\Exception $e) {
            return $date; // Return original if parsing fails
        }
    }

    /**
     * Format datetime for display (always includes time)
     *
     * @param string|null $datetime Datetime in Y-m-d H:i:s format
     * @return string|null Formatted datetime or null if input is null/empty
     */
    public static function formatDateTime(?string $datetime): ?string
    {
        return self::format($datetime, true);
    }

    /**
     * Convert a date from display format to ISO format for storage
     *
     * @param string|null $date Date in user's display format
     * @return string|null Date in Y-m-d format or null if input is null/empty
     */
    public static function toIso(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        // Already in ISO format? Return only the date portion (first 10 chars)
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return substr($date, 0, 10);
        }

        // Try to parse from EU format
        $format = self::getDisplayFormat();
        try {
            if ($format === self::FORMAT_EU) {
                $datetime = \DateTime::createFromFormat('d-m-Y', substr($date, 0, 10));
                if ($datetime) {
                    return $datetime->format('Y-m-d');
                }
            }

            // Fallback: let PHP parse it
            $datetime = new \DateTime($date);
            return $datetime->format('Y-m-d');
        } catch (\Exception $e) {
            return $date; // Return original if parsing fails
        }
    }

    /**
     * Get PHP date format string based on current setting
     *
     * @param bool $withTime Include time component
     * @return string PHP date format string
     */
    public static function getPhpFormat(bool $withTime = false): string
    {
        $format = self::getDisplayFormat();

        if ($withTime) {
            return $format === self::FORMAT_EU ? self::FORMAT_EU_TIME : self::FORMAT_ISO_TIME;
        }

        return $format;
    }

    /**
     * Get JavaScript-compatible format pattern
     * Returns the format string for use in JavaScript
     */
    public static function getJsPattern(): string
    {
        return self::getDisplayFormat();
    }
}
