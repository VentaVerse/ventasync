<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allowlist HTML sanitiser for the catalog description fields
 * (product_description.description / category_description.description),
 * which are edited through Summernote (resources/views/catalog/products/
 * partials/wysiwyg.blade.php) and stored as raw HTML.
 *
 * Built on DOMDocument, never regex: regex cannot parse HTML's nesting or
 * attribute quoting correctly, which is exactly the class of bug that lets
 * hand-rolled tag stripping be bypassed (nested/malformed tags, unquoted
 * attributes, encoded entities). DOMDocument gives a real parse tree that
 * this class walks and rebuilds from an explicit allowlist, so anything not
 * on the list is either unwrapped (its own tag removed, its already-clean
 * children kept) or, for tags whose "content" is code rather than text,
 * dropped along with everything inside it.
 *
 * The allowlist is read from the Summernote toolbar configuration in
 * wysiwyg.blade.php, not guessed:
 *
 *   ['style',  ['style']]             -> p, h1-h6, blockquote, pre
 *   ['font',   ['bold','italic','underline','clear']] -> b/strong, i/em, u
 *                                         (clear = removeFormat, no tag)
 *   ['para',   ['ul','ol','paragraph']] -> ul, ol, li (their required
 *                                         child); the "paragraph" dropdown
 *                                         is align/indent, which sets a
 *                                         style="" attribute this class
 *                                         does not allow on any tag (see
 *                                         below) - alignment is lost on
 *                                         sanitisation, formatting is not
 *   ['insert', ['link','table']]      -> a[href], table/thead/tbody/tr/td/th
 *   ['view',   ['codeview']]          -> codeview is exactly the vector
 *                                         being defended against (it lets
 *                                         an editor type raw HTML, which is
 *                                         how the reviewer's <script>
 *                                         payload was stored in the first
 *                                         place), so it grants no tags of
 *                                         its own - everything it can
 *                                         produce still has to clear this
 *                                         allowlist
 *
 * img[src,alt] is included even though there is no image toolbar button:
 * Summernote is initialised in wysiwyg.blade.php with no
 * callbacks.onImageUpload, so its documented default behaviour applies -
 * an image pasted or dropped into the editor is embedded directly as a
 * base64 <img src="data:image/...;base64,...">. That is a real, reachable
 * path for genuine product/category descriptions, so img stays allowed
 * with src restricted to http/https/relative or data:image/<raster>
 * (below) and no other attribute.
 *
 * No tag is allowed a "style", "class", or "id" attribute, or any "on*"
 * handler: only the specific attributes named per tag in ALLOWED_TAGS
 * survive. That is a deliberate feature loss (alignment, indent, custom
 * classes) traded for not having to reason about what CSS or arbitrary
 * attribute soup could do; nothing in the toolbar needs more than that.
 */
class HtmlSanitizer
{
    /**
     * tag => allowed attribute names (lowercase). A tag not listed here at
     * all is not kept as an element - see sanitizeNode().
     */
    private const ALLOWED_TAGS = [
        // 'style' dropdown: Summernote's default styleTags is
        // ['p', 'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'].
        'p' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'blockquote' => [],
        'pre' => [],

        // 'font' group: bold / italic / underline. Browsers' execCommand
        // implementations vary between <b>/<strong> and <i>/<em>; both
        // pairs are kept since either can come out of the same button
        // depending on the browser that saved the content.
        'b' => [], 'strong' => [],
        'i' => [], 'em' => [],
        'u' => [],

        // Soft line break (Shift+Enter within a paragraph).
        'br' => [],

        // 'para' group: ul / ol buttons; li is their required child.
        'ul' => [],
        'ol' => [],
        'li' => [],

        // 'insert' group: link.
        'a' => ['href'],

        // 'insert' group: table. Summernote's basic table insert is a
        // plain grid (table > tbody > tr > td), but codeview lets a user
        // type a <thead>/<th> table too, so both are kept - table
        // structure, not table styling, which is what this range is
        // actually defending.
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'td' => [], 'th' => [],

        // Not a toolbar button - see class docblock. src is validated
        // separately in sanitizeUrlAttribute().
        'img' => ['src', 'alt'],
    ];

    /**
     * Tags whose entire subtree is discarded rather than unwrapped. Their
     * "content" is not user-facing text - it is markup, script, or form
     * controls - so keeping the text (as unwrap() does for everything
     * else) would just leave the payload's source sitting in the
     * description as visible clutter instead of removing it. Most of these
     * cannot appear from the toolbar at all; they are here because
     * codeview lets a user (or an attacker with manage_products/
     * manage_categories) type anything.
     */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'noscript', 'noframes', 'form', 'button', 'select',
        'option', 'optgroup', 'textarea', 'svg', 'math', 'template', 'link',
        'meta', 'base', 'audio', 'video', 'source', 'track', 'canvas',
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https'];

    /**
     * Raster image types only for data: URIs. data:image/svg+xml is
     * deliberately excluded - an SVG document can carry its own <script>
     * or onload handler, so "it's an image" does not make it inert the way
     * png/jpeg/gif/webp are.
     */
    private const DATA_IMAGE_PATTERN = '#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/]+=*$#';

    /**
     * Sanitise stored/incoming description HTML down to the allowlist
     * above. Safe to call on already-clean content (idempotent) and on
     * empty/null input.
     */
    public static function sanitize(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument();
        $priorState = libxml_use_internal_errors(true);

        // The "<?xml encoding=...>" prefix is a documented trick to make
        // DOMDocument treat the string as UTF-8 (it has no HTTP headers or
        // <meta charset> to infer that from otherwise, and falls back to
        // Latin-1, mangling any multibyte text). LIBXML_HTML_NOIMPLIED +
        // LIBXML_HTML_NODEFDTD stop it from inventing a wrapping
        // <html><body> or a DOCTYPE around the fragment, so $dom's direct
        // children below are exactly the input's top-level nodes, nothing
        // synthesised.
        /* The fragment rides inside one wrapper element. Without it, text
         * that stands at the top level with no tag around it ("A warm
         * overdrive.", the shape every marketplace description takes) was
         * not among the document's element children and came out as an
         * empty string: every imported plain-text description was lost
         * (found 2026-09-07, the import-is-ready work). The wrapper is
         * never part of the output. */
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

    /**
     * @param DOMDocument $dom owning document, needed for saveHTML/creation calls
     * @param DOMNode $context node whose children get sanitised in place
     */
    private static function sanitizeChildren(DOMDocument $dom, DOMNode $context): void
    {
        // DOMNodeList is live over the document; snapshot it first since
        // sanitizeNode() below removes/replaces nodes as it goes.
        foreach (iterator_to_array($context->childNodes) as $child) {
            self::sanitizeNode($dom, $child);
        }
    }

    private static function sanitizeNode(DOMDocument $dom, DOMNode $node): void
    {
        if ($node instanceof DOMText) {
            return; // character data; saveHTML() escapes it on output
        }

        if (!$node instanceof DOMElement) {
            // Comments, processing instructions, CDATA sections, doctypes:
            // none of these are things the editor's toolbar or codeview
            // should be producing as real content.
            $node->parentNode?->removeChild($node);
            return;
        }

        $tag = strtolower($node->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            $node->parentNode?->removeChild($node);
            return;
        }

        // Recurse first so this node's children are already clean by the
        // time we decide whether to keep, unwrap, or leave it alone.
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

    /**
     * Replace $node with its own (already-sanitised) children, dropping
     * only the tag itself. Used for tags that are not on the allowlist but
     * whose content is ordinary user text worth keeping.
     */
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
        // Strip ASCII control characters (tabs, newlines, ...) wherever
        // they occur in the string, not just at the ends: browsers ignore
        // them when sniffing a URL's scheme, so "java\tscript:alert(1)"
        // resolves to the javascript: scheme despite not literally
        // starting with it. A naive check on the raw string would miss that.
        $stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
        $trimmed = ltrim($stripped);

        if ($trimmed === '') {
            return false;
        }

        // Protocol-relative ("//evil.example/x") is an absolute off-site
        // URL in every browser, not a relative one - reject it the same
        // way ProductController::isSafeLocalReturnUrl() rejects it for the
        // `_return` open-redirect guard elsewhere in this codebase.
        if (str_starts_with($trimmed, '//')) {
            return false;
        }

        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $trimmed, $m)) {
            return true; // no scheme: relative path, "#fragment", "?query"
        }

        $scheme = strtolower($m[1]);

        if ($isImageSrc && $scheme === 'data') {
            return (bool) preg_match(self::DATA_IMAGE_PATTERN, $trimmed);
        }

        return in_array($scheme, self::ALLOWED_URL_SCHEMES, true);
    }
}
