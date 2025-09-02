<?php
declare(strict_types=1);

namespace App\Support;

final class Sanitizer
{
    /**
     * Sanitize HTML content, allowing a safe subset of tags and attributes.
     * This prevents XSS by parsing the HTML and rebuilding it with a whitelist.
     */
    public static function html(string $dirtyHtml): string
    {
        if (trim($dirtyHtml) === '') {
            return '';
        }

        $allowedTags = [
            'p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li',
            'blockquote', 'h2', 'h3', 'h4', 'hr', 'a'
        ];

        $allowedAttributes = [
            'a' => ['href', 'title', 'target', 'rel']
        ];

        // Use DOMDocument to parse HTML to prevent regex-based bypasses
        $dom = new \DOMDocument();
        // Suppress warnings for malformed HTML, as we are cleaning it
        @$dom->loadHTML('<div>' . $dirtyHtml . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Get the root element (the div we wrapped around the content)
        $root = $dom->documentElement;
        if (!$root) return '';

        self::sanitizeNode($root, $allowedTags, $allowedAttributes);

        // Extract the sanitized HTML
        $cleanHtml = '';
        foreach ($root->childNodes as $node) {
            $cleanHtml .= $dom->saveHTML($node);
        }

        return $cleanHtml;
    }

    private static function sanitizeNode(\DOMNode $node, array $allowedTags, array $allowedAttributes): void
    {
        if ($node->hasChildNodes()) {
            // Iterate backwards to safely remove nodes
            for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
                self::sanitizeNode($node->childNodes->item($i), $allowedTags, $allowedAttributes);
            }
        }

        if ($node->nodeType === XML_ELEMENT_NODE) {
            if (!in_array(strtolower($node->nodeName), $allowedTags)) {
                $node->parentNode->removeChild($node);
                return;
            }

            if ($node->hasAttributes()) {
                foreach (iterator_to_array($node->attributes) as $attr) {
                    $attrName = strtolower($attr->name);
                    $allowed = $allowedAttributes[strtolower($node->nodeName)] ?? [];

                    if (!in_array($attrName, $allowed)) {
                        $node->removeAttribute($attr->name);
                    } elseif ($attrName === 'href') {
                        // For hrefs, ensure they are safe URLs (http, https, mailto)
                        $url = $attr->value;
                        if (!preg_match('~^(https?://|mailto:|/|#)~i', $url)) {
                            $node->removeAttribute($attr->name);
                        }
                    }
                }
            }
        }
    }
}
