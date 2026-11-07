<?php
declare(strict_types=1);

/**
 * Server-side cleaning of rich text. Only an allowlist of tags and attributes survives.
 * Never trust the browser's cleaning: this runs on every save.
 */
final class Sanitizer
{
    /** Allowed tags and, for each, the allowed attributes. */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'h2' => [],
        'h3' => [],
        'strong' => [],
        'em' => [],
        'a' => ['href', 'class', 'data-media-id'],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'img' => ['src', 'alt', 'data-media-id'],
    ];

    /** Tags converted to an allowed equivalent. */
    private const RENAME = [
        'b' => 'strong',
        'i' => 'em',
        'h1' => 'h2',
        'h4' => 'h3',
        'h5' => 'h3',
        'h6' => 'h3',
    ];

    /** Tags removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript',
        'template', 'svg', 'math', 'form', 'input', 'button', 'select', 'option', 'textarea', 'head',
        'title', 'meta', 'link', 'base', 'video', 'audio', 'source', 'track', 'canvas', 'picture', 'map',
    ];

    private const BLOCKS = ['p', 'h2', 'h3', 'ul', 'ol', 'blockquote', 'img'];

    public static function html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $source = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // The meta tag tells libxml the text is UTF-8.
        $source->loadHTML(
            '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="sanitizer-root">'
            . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $source->getElementById('sanitizer-root');
        if ($root === null) {
            return '';
        }
        $out = new DOMDocument('1.0', 'UTF-8');
        $container = $out->createElement('div');
        $out->appendChild($container);
        self::copyChildren($root, $container, $out);
        self::wrapLooseInline($container, $out);
        self::removeEmptyEdges($container);

        $result = '';
        foreach ($container->childNodes as $child) {
            $result .= $out->saveHTML($child);
        }
        return trim($result);
    }

    private static function copyChildren(DOMNode $from, DOMNode $to, DOMDocument $out): void
    {
        foreach ($from->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text = $child->nodeValue ?? '';
                if ($text !== '') {
                    $to->appendChild($out->createTextNode($text));
                }
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue; // comments, processing instructions...
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                continue;
            }
            if (isset(self::RENAME[$tag])) {
                $tag = self::RENAME[$tag];
            }
            if (!isset(self::ALLOWED[$tag])) {
                // Unknown tag (span, div, font, o:p...): keep its content only.
                if (in_array($tag, ['div', 'section', 'article', 'header', 'footer', 'table', 'tr'], true)
                    && $to->nodeName === 'div') {
                    // A block-level wrapper at top level becomes a paragraph boundary.
                    $p = $out->createElement('p');
                    self::copyChildren($child, $p, $out);
                    if ($p->hasChildNodes()) {
                        $to->appendChild($p);
                    }
                    continue;
                }
                self::copyChildren($child, $to, $out);
                continue;
            }
            $element = $out->createElement($tag);
            if (!self::copyAttributes($child, $element, $tag)) {
                continue; // e.g. an image that is not from the media library
            }
            if ($tag !== 'img' && $tag !== 'br') {
                self::copyChildren($child, $element, $out);
            }
            if ($tag === 'a' && !$element->hasAttribute('href')) {
                // A link without a safe address keeps only its text.
                while ($element->firstChild) {
                    $to->appendChild($element->firstChild);
                }
                continue;
            }
            $to->appendChild($element);
        }
    }

    private static function copyAttributes(DOMElement $from, DOMElement $to, string $tag): bool
    {
        $allowed = self::ALLOWED[$tag];
        foreach ($allowed as $name) {
            if (!$from->hasAttribute($name)) {
                continue;
            }
            $value = trim($from->getAttribute($name));
            if ($name === 'href') {
                $value = self::safeHref($value);
                if ($value === null) {
                    continue;
                }
            } elseif ($name === 'src') {
                if (!preg_match('~^/media/[a-z0-9]+(-\d+)?\.(webp|jpg|png)(\?v=\d+)?$~', $value)) {
                    return false;
                }
            } elseif ($name === 'class') {
                if (!preg_match('/(^|\s)btn(\s|$)/', $value)) {
                    continue;
                }
                $value = 'btn';
            } elseif ($name === 'data-media-id') {
                if (!preg_match('/^\d{1,9}$/', $value)) {
                    continue;
                }
            } elseif ($name === 'alt') {
                $value = mb_substr($value, 0, 300, 'UTF-8');
            }
            $to->setAttribute($name, $value);
        }
        if ($tag === 'img' && !$to->hasAttribute('src')) {
            return false;
        }
        return true;
    }

    /** Accept web, e-mail, phone and on-site links. Anything else (javascript:, data:...) is refused. */
    public static function safeHref(string $href): ?string
    {
        $clean = (string) preg_replace('/[\x00-\x20\x7F]+/', '', $href);
        if ($clean === '') {
            return null;
        }
        if (preg_match('~^https?://[^/\s]~i', $clean) || preg_match('~^(mailto|tel):~i', $clean)) {
            return $href === $clean ? $href : $clean;
        }
        if ($clean[0] === '#' || ($clean[0] === '/' && (strlen($clean) === 1 || $clean[1] !== '/'))) {
            return $clean;
        }
        return null;
    }

    /** Text or inline tags directly at top level are grouped into paragraphs. */
    private static function wrapLooseInline(DOMElement $container, DOMDocument $out): void
    {
        $nodes = [];
        foreach ($container->childNodes as $child) {
            $nodes[] = $child;
        }
        $paragraph = null;
        foreach ($nodes as $node) {
            $isBlock = $node instanceof DOMElement && in_array($node->tagName, self::BLOCKS, true);
            if ($isBlock) {
                $paragraph = null;
                continue;
            }
            if ($node instanceof DOMText && trim($node->nodeValue ?? '') === '' && $paragraph === null) {
                $container->removeChild($node);
                continue;
            }
            if ($paragraph === null) {
                $paragraph = $out->createElement('p');
                $container->insertBefore($paragraph, $node);
            }
            $paragraph->appendChild($node);
        }
    }

    /** Remove empty paragraphs at the very start and end. */
    private static function removeEmptyEdges(DOMElement $container): void
    {
        foreach (['firstChild', 'lastChild'] as $edge) {
            while ($container->$edge instanceof DOMElement
                && $container->$edge->tagName === 'p'
                && trim(str_replace("\xC2\xA0", ' ', $container->$edge->textContent)) === ''
                && $container->$edge->getElementsByTagName('img')->length === 0) {
                $container->removeChild($container->$edge);
            }
        }
    }

    /** Plain text version of rich text (for descriptions and excerpts). */
    public static function toText(string $html): string
    {
        $html = (string) preg_replace('~<(br|/p|/h2|/h3|/li|/blockquote)\b[^>]*>~i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
