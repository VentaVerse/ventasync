<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'blockquote' => [],
        'pre' => [],

        'b' => [], 'strong' => [],
        'i' => [], 'em' => [],
        'u' => [],

        'br' => [],

        'ul' => [],
        'ol' => [],
        'li' => [],

        'a' => ['href'],

        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => [], 'th' => [],

        'img' => ['src', 'alt'],
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'noscript', 'noframes', 'form', 'button', 'select',
        'option', 'optgroup', 'textarea', 'svg', 'math', 'template', 'link',
        'meta', 'base', 'audio', 'video', 'source', 'track', 'canvas',
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https'];

    private const DATA_IMAGE_PATTERN = '#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+=*$#';

    public static function sanitize(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument();
        $priorState = libxml_use_internal_errors(true);

        // The xml prefix makes DOMDocument read UTF-8; without it multibyte text is mangled.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($priorState);

        $wrapper = null;
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'div') {
                $wrapper = $node;
                break;
            }
        }
        if (! $wrapper) {
            return '';
        }

        self::sanitizeChildren($dom, $wrapper);

        $out = '';
        foreach (iterator_to_array($wrapper->childNodes) as $node) {
            $out .= $dom->saveHTML($node);
        }

        return $out;
    }

    private static function sanitizeChildren(DOMDocument $dom, DOMNode $context): void
    {
        foreach (iterator_to_array($context->childNodes) as $child) {
            self::sanitizeNode($dom, $child);
        }
    }

    private static function sanitizeNode(DOMDocument $dom, DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            return;
        }

        if (!$node instanceof DOMElement) {
            $node->parentNode?->removeChild($node);
            return;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $node->parentNode?->removeChild($node);
            return;
        }

        self::sanitizeChildren($dom, $node);

        if (!array_key_exists($tag, self::ALLOWED_TAGS)) {
            self::unwrap($node);
            return;
        }

        self::stripDisallowedAttributes($node, self::ALLOWED_TAGS[$tag]);

        if ($tag === 'a') {
            self::sanitizeUrlAttribute($node, 'href', false);
        }
        if ($tag === 'img') {
            self::sanitizeUrlAttribute($node, 'src', true);
        }
    }

    private static function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    private static function stripDisallowedAttributes(DOMElement $node, array $allowedAttrs): void
    {
        foreach (iterator_to_array($node->attributes ?? []) as $attr) {
            if (!in_array(strtolower($attr->name), $allowedAttrs, true)) {
                $node->removeAttribute($attr->name);
            }
        }
    }

    private static function sanitizeUrlAttribute(DOMElement $node, string $attr, bool $isImageSrc): void
    {
        if (!$node->hasAttribute($attr)) {
            return;
        }

        if (!self::isSafeUrl($node->getAttribute($attr), $isImageSrc)) {
            $node->removeAttribute($attr);
        }
    }

    private static function isSafeUrl(string $value, bool $isImageSrc): bool
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $trimmed = ltrim($stripped);

        if ($trimmed === '') {
            return false;
        }

        if (str_starts_with($trimmed, '//')) {
            return false;
        }

        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $trimmed, $m)) {
            return true;
        }

        $scheme = strtolower($m[1]);

        if ($isImageSrc && $scheme === 'data') {
            return (bool) preg_match(self::DATA_IMAGE_PATTERN, $trimmed);
        }

        return in_array($scheme, self::ALLOWED_URL_SCHEMES, true);
    }
}
