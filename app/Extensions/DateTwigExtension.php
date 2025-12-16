<?php
declare(strict_types=1);

namespace App\Extensions;

use App\Support\DateHelper;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension for date formatting.
 *
 * Provides filters and functions for consistent date display based on user settings.
 */
class DateTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('date_format', [$this, 'formatDate']),
            new TwigFilter('datetime_format', [$this, 'formatDateTime']),
            new TwigFilter('replace_year', [$this, 'replaceYear']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('date_format_pattern', [$this, 'getPattern']),
        ];
    }

    /**
     * Format a date for display (without time)
     *
     * Usage in Twig: {{ album.shoot_date|date_format }}
     *
     * @param string|null $date Date in Y-m-d format
     * @return string|null Formatted date
     */
    public function formatDate(?string $date): ?string
    {
        return DateHelper::format($date, false);
    }

    /**
     * Format a datetime for display (with time)
     *
     * Usage in Twig: {{ user.created_at|datetime_format }}
     *
     * @param string|null $datetime Datetime in Y-m-d H:i:s format
     * @return string|null Formatted datetime
     */
    public function formatDateTime(?string $datetime): ?string
    {
        return DateHelper::formatDateTime($datetime);
    }

    /**
     * Get the current date format pattern
     *
     * Usage in Twig: {{ date_format_pattern() }}
     * Returns 'Y-m-d' or 'd-m-Y' for use in JavaScript
     *
     * @return string The format pattern
     */
    public function getPattern(): string
    {
        return DateHelper::getDisplayFormat();
    }

    /**
     * Replace {year} placeholder with current year
     *
     * Usage in Twig: {{ settings['site.copyright']|replace_year }}
     *
     * @param string|null $text Text containing {year} placeholder
     * @return string Text with {year} replaced by current year
     */
    public function replaceYear(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        return str_replace('{year}', date('Y'), $text);
    }
}
