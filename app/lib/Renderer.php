<?php
declare(strict_types=1);

/** Turns saved blocks into public HTML. */
final class Renderer
{
    /** @var bool */
    public static $hasEmbed = false;

    /** @var array<int,array<string,mixed>> */
    private static $mediaCache = [];

    /** Load in one query all media used by a list of pages. */
    public static function preloadMedia(array $pages): void
    {
        $ids = [];
        foreach ($pages as $page) {
            $ids = array_merge($ids, Pages::mediaIds($page));
        }
        foreach (Media::findMany($ids) as $id => $media) {
            self::$mediaCache[$id] = $media;
        }
    }

    /** @return array<string,mixed>|null */
    public static function media(?int $id): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        if (!array_key_exists($id, self::$mediaCache)) {
            self::$mediaCache[$id] = Media::find($id);
        }
        return self::$mediaCache[$id];
    }

    /**
     * Responsive image tag.
     * @param string $sizes the "sizes" attribute
     */
    public static function img(array $media, string $sizes, ?string $alt = null, bool $lazy = true, string $class = ''): string
    {
        list($w, $h) = Media::displaySize($media);
        $altText = $alt !== null ? $alt : (string) $media['alt'];
        return '<img src="' . e(Media::url($media, 960)) . '" srcset="' . e(Media::srcset($media))
            . '" sizes="' . e($sizes) . '" width="' . $w . '" height="' . $h . '" alt="' . e($altText) . '"'
            . ($class !== '' ? ' class="' . e($class) . '"' : '')
            . ($lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"') . '>';
    }

    public static function blocks(array $blocks): string
    {
        $html = '';
        $seenH2 = false;
        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $content = self::text((string) ($block['html'] ?? ''));
                if (trim($content) !== '') {
                    $html .= '<div class="block block-text prose">' . $content . '</div>';
                    $seenH2 = $seenH2 || stripos($content, '<h2') !== false;
                }
            } elseif ($type === 'cards') {
                // Card titles sit one level under the last section heading.
                $html .= self::cards(is_array($block['cards'] ?? null) ? $block['cards'] : [], $seenH2 ? 'h3' : 'h2');
            } elseif ($type === 'embed') {
                $html .= self::embed((string) ($block['url'] ?? ''));
            }
        }
        return $html;
    }

    /** Rich text: images are rebuilt from the media library, so replaced files show at once. */
    public static function text(string $html): string
    {
        $html = (string) preg_replace_callback('~<img\b[^>]*>~i', function (array $m): string {
            $tag = $m[0];
            if (!preg_match('/data-media-id="(\d+)"/', $tag, $id)) {
                return '';
            }
            $media = self::media((int) $id[1]);
            if ($media === null || $media['kind'] !== 'image') {
                return '';
            }
            $alt = null;
            if (preg_match('/\balt="([^"]*)"/', $tag, $a)) {
                $alt = html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($alt === '') {
                    $alt = null;
                }
            }
            return self::img($media, '(min-width: 48rem) 42rem, calc(100vw - 2.5rem)', $alt);
        }, $html);
        // Links to library PDFs: always point to the current file.
        $html = (string) preg_replace_callback('~<a\b([^>]*)data-media-id="(\d+)"([^>]*)>~i', function (array $m): string {
            $media = self::media((int) $m[2]);
            if ($media === null) {
                return '<a' . $m[1] . $m[3] . '>';
            }
            $attrs = (string) preg_replace('/\shref="[^"]*"/', '', $m[1] . $m[3]);
            return '<a href="' . e(Media::url($media)) . '"' . rtrim($attrs) . '>';
        }, $html);
        return $html;
    }

    /** @param array<int,array<string,mixed>> $cards */
    public static function cards(array $cards, string $headingTag = 'h2'): string
    {
        if (!$cards) {
            return '';
        }
        $html = '<ul class="block block-cards cards" role="list">';
        foreach ($cards as $card) {
            $media = self::media(isset($card['media_id']) ? (int) $card['media_id'] : null);
            $url = (string) ($card['url'] ?? '');
            $title = e((string) ($card['title'] ?? ''));
            $html .= '<li class="card' . ($url !== '' ? ' card-link' : '') . '">';
            if ($media !== null && $media['kind'] === 'image') {
                $html .= '<div class="card-image">' . self::img($media, '(min-width: 64rem) 22rem, (min-width: 40rem) 45vw, 100vw', '') . '</div>';
            }
            $html .= '<div class="card-body"><' . $headingTag . ' class="card-title">';
            $html .= $url !== '' ? '<a href="' . e($url) . '">' . $title . '</a>' : $title;
            $html .= '</' . $headingTag . '>';
            if (!empty($card['text'])) {
                $html .= '<p class="card-text">' . nl2br(e((string) $card['text']), false) . '</p>';
            }
            $html .= '</div></li>';
        }
        return $html . '</ul>';
    }

    /** Video: a button first; the player (and any request to YouTube or Vimeo) loads on click. */
    public static function embed(string $url): string
    {
        $video = Embed::parse($url);
        if ($video === null) {
            return '';
        }
        self::$hasEmbed = true;
        $provider = Embed::providerName($video);
        return '<div class="block block-embed"><div class="video" data-src="' . e(Embed::playerUrl($video)) . '" data-provider="' . e($provider) . '">'
            . '<button type="button" class="video-play"><span class="video-icon" aria-hidden="true"></span>'
            . '<span>Lire la vidéo</span><small>La vidéo est hébergée par ' . e($provider) . '. Elle se charge quand vous cliquez.</small></button>'
            . '<noscript><a href="' . e($url) . '">Voir la vidéo sur ' . e($provider) . '</a></noscript>'
            . '</div></div>';
    }

    /** Automatic list of the sub-pages of a page. */
    public static function childList(array $children): string
    {
        if (!$children) {
            return '';
        }
        $cards = [];
        foreach ($children as $child) {
            $cards[] = [
                'media_id' => $child['header_media_id'] !== null ? (int) $child['header_media_id'] : null,
                'title' => $child['title'],
                'text' => $child['seo_description'] !== '' ? $child['seo_description'] : Pages::autoDescription($child),
                'url' => Pages::path($child),
            ];
        }
        return '<section class="children" aria-labelledby="children-title"><h2 id="children-title">Dans cette rubrique</h2>'
            . self::cards($cards, 'h3') . '</section>';
    }
}
