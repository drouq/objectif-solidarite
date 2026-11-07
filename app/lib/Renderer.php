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
        // A donation button in a text uses the donation colour.
        $html = str_replace('href="{{HELLOASSO_DON_URL}}" class="btn"', 'href="{{HELLOASSO_DON_URL}}" class="btn btn-primary"', $html);
        // HelloAsso links written as {{HELLOASSO_DON_URL}}...: the addresses from Réglages.
        $html = (string) preg_replace_callback('~href="(\{\{HELLOASSO_[A-Z_]+\}\})"~', function (array $m): string {
            return 'href="' . e(self::href($m[1])) . '"';
        }, $html);
        $html = self::tables($html);
        return KeyFigures::replaceIn($html);
    }

    /** A link address, with the HelloAsso placeholders replaced. */
    public static function href(string $url): string
    {
        if ($url === '{{HELLOASSO_DON_URL}}') {
            return Settings::donUrl();
        }
        if ($url === '{{HELLOASSO_ADHESION_URL}}') {
            return Settings::adhesionUrl();
        }
        if ($url === '{{HELLOASSO_URL}}') {
            return Settings::helloAssoUrl();
        }
        return $url;
    }

    /**
     * Tables: the first row is the header row (the editor cannot mark one), and a wide table
     * scrolls sideways inside its own box on a phone instead of widening the page.
     */
    public static function tables(string $html): string
    {
        if (stripos($html, '<table') === false) {
            return $html;
        }
        return (string) preg_replace_callback('~<table>(.*?)</table>~s', function (array $m): string {
            $inner = $m[1];
            if (strpos($inner, '<th') === false) {
                $inner = (string) preg_replace_callback('~<tr>(.*?)</tr>~s', function (array $row): string {
                    return '<tr>' . str_replace(['<td>', '</td>'], ['<th scope="col">', '</th>'], $row[1]) . '</tr>';
                }, $inner, 1);
            } else {
                $inner = str_replace('<th>', '<th scope="col">', $inner);
            }
            return '<div class="table-wrap" role="region" aria-label="Tableau" tabindex="0"><table>' . $inner . '</table></div>';
        }, $html);
    }

    /**
     * @param array<int,array<string,mixed>> $cards
     * @param bool $photoLed page cards: a neutral tile stands in for a missing photo, so the grid keeps its shape
     */
    public static function cards(array $cards, string $headingTag = 'h2', bool $photoLed = false): string
    {
        if (!$cards) {
            return '';
        }
        $hasImage = false;
        foreach ($cards as $card) {
            $hasImage = $hasImage || !empty($card['media_id']);
        }
        // A grid without any photo (figures, documents) is shown as plain text cards.
        $style = $photoLed ? ' cards-photo' : ($hasImage ? '' : ' cards-text');
        $html = '<ul class="block block-cards cards' . $style . '" role="list">';
        foreach ($cards as $card) {
            $media = self::media(isset($card['media_id']) ? (int) $card['media_id'] : null);
            $url = self::href((string) ($card['url'] ?? ''));
            $title = KeyFigures::replaceIn(e((string) ($card['title'] ?? '')));
            $html .= '<li class="card' . ($url !== '' ? ' card-link' : '') . '">';
            if ($media !== null && $media['kind'] === 'image') {
                $html .= '<div class="card-image">' . self::img($media, '(min-width: 64rem) 22rem, (min-width: 40rem) 45vw, 100vw', '') . '</div>';
            } elseif ($photoLed || $hasImage) {
                $html .= '<div class="card-image card-image-empty" aria-hidden="true"></div>';
            }
            $html .= '<div class="card-body"><' . $headingTag . ' class="card-title">';
            $html .= $url !== '' ? '<a href="' . e($url) . '">' . $title . '</a>' : $title;
            $html .= '</' . $headingTag . '>';
            if (!empty($card['text'])) {
                $html .= '<p class="card-text">' . nl2br(KeyFigures::replaceIn(e((string) $card['text'])), false) . '</p>';
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
            . self::cards($cards, 'h3', true) . '</section>';
    }
}
