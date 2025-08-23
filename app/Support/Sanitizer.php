<?php
declare(strict_types=1);

namespace App\Support;

final class Sanitizer
{
    /**
     * Allow a minimal set of formatting tags and safe attributes.
     */
    public static function html(string $html): string
    {
        if ($html === '') return '';
        $allowed = '<p><br><strong><em><b><i><ul><ol><li><blockquote><a><h2><h3><h4><hr>';
        $san = strip_tags($html, $allowed);
        // Remove on* event handlers and javascript: URLs
        $san = preg_replace('/\s+on[a-zA-Z]+\s*=\s*(["\"])?.*?\1/i', '', $san ?? '');
        $san = preg_replace('/(href|src)\s*=\s*(["\"])javascript:[^\2]*\2/i', '$1="#"', $san ?? '');
        // Remove style attributes to keep design consistent
        $san = preg_replace('/\sstyle\s*=\s*(["\"]).*?\1/i', '', $san ?? '');
        return $san ?? '';
    }
}

