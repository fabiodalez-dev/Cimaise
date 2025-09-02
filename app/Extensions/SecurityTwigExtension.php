<?php
declare(strict_types=1);

namespace App\Extensions;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class SecurityTwigExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('safe_html', [$this, 'sanitizeHtml'], ['is_safe' => ['html']]),
        ];
    }

    public function sanitizeHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        // Basic whitelist sanitizer without external deps
        $allowedTags = [
            'p','a','strong','em','ul','ol','li','blockquote','h2','h3','h4','hr','br','span'
        ];
        $allowedAttrs = [
            'a' => ['href','rel','target'],
            'span' => ['class'],
        ];

        // Strip dangerous protocols
        $html = preg_replace('#(?i)javascript:\s*#', '', $html ?? '');

        $doc = new \DOMDocument();
        $doc->strictErrorChecking = false;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html, LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $this->sanitizeNode($doc, $allowedTags, $allowedAttrs);
        $out = $doc->saveHTML();
        return $out ?: '';
    }

    private function sanitizeNode(\DOMNode $node, array $allowedTags, array $allowedAttrs): void
    {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            if (!in_array($tag, $allowedTags, true)) {
                // Replace disallowed element with its text content
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    return; // children already reparented
                }
            } else {
                // Filter attributes
                if ($node->hasAttributes()) {
                    /** @var \DOMNamedNodeMap $attrs */
                    $attrs = $node->attributes;
                    for ($i = $attrs->length - 1; $i >= 0; $i--) {
                        $attr = $attrs->item($i);
                        $name = strtolower($attr->nodeName);
                        $allowed = $allowedAttrs[$tag] ?? [];
                        if (!in_array($name, $allowed, true)) {
                            $node->removeAttributeNode($attr);
                            continue;
                        }
                        if ($tag === 'a' && $name === 'href') {
                            $val = (string)$attr->nodeValue;
                            if (preg_match('#^\s*(javascript:|data:)#i', $val)) {
                                $node->removeAttribute('href');
                            }
                        }
                        if ($tag === 'a' && $name === 'target') {
                            // Normalize target
                            $node->setAttribute('rel', 'noopener noreferrer');
                        }
                    }
                }
            }
        }
        // Recurse on children (snapshot list as it may change)
        $children = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            $this->sanitizeNode($child, $allowedTags, $allowedAttrs);
        }
    }
}

