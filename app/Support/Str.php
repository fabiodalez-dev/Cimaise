<?php
declare(strict_types=1);

namespace App\Support;

final class Str
{
    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('~[\p{Zs}]+~u', '-', $value) ?? $value; // spaces to dash
        $value = preg_replace('~[^a-z0-9\-]+~', '', $value) ?? $value; // strip non-alnum
        $value = preg_replace('~-+~', '-', $value) ?? $value; // collapse dashes
        return trim($value, '-');
    }
}

