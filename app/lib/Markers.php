<?php
declare(strict_types=1);

/**
 * Content markers: "[À VÉRIFIER : ...]" (unverified) and "[À COMPLÉTER : ...]" (missing).
 * The page editor warns while a page still holds some; the content import counts them.
 */
final class Markers
{
    public const PATTERN = '/\[À (?:VÉRIFIER|COMPLÉTER)\b[^\]\n]{0,300}\]?/u';

    /** @return string[] markers found in a plain or HTML text, in order */
    public static function inText(string $text): array
    {
        if (strpos($text, '[À ') === false) {
            return [];
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return preg_match_all(self::PATTERN, $text, $m) ? $m[0] : [];
    }

    /**
     * Markers in a list of blocks (text, card titles, card texts and links, video addresses).
     * @param array<int,array<string,mixed>> $blocks
     * @return string[]
     */
    public static function inBlocks(array $blocks): array
    {
        $found = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $found = array_merge($found, self::inText(Sanitizer::toText((string) ($block['html'] ?? ''))));
            } elseif ($type === 'cards' && is_array($block['cards'] ?? null)) {
                foreach ($block['cards'] as $card) {
                    if (is_array($card)) {
                        foreach (['title', 'text', 'url'] as $field) {
                            $found = array_merge($found, self::inText((string) ($card[$field] ?? '')));
                        }
                    }
                }
            } elseif ($type === 'embed') {
                $found = array_merge($found, self::inText((string) ($block['url'] ?? '')));
            }
        }
        return $found;
    }

    /** @return string[] markers in a page: title, search-engine texts and blocks */
    public static function inPage(array $page): array
    {
        $blocks = is_array($page['blocks'] ?? null) ? $page['blocks'] : json_decode((string) ($page['blocks'] ?? '[]'), true);
        $found = [];
        foreach (['title', 'seo_title', 'seo_description'] as $field) {
            $found = array_merge($found, self::inText((string) ($page[$field] ?? '')));
        }
        return array_merge($found, self::inBlocks(is_array($blocks) ? $blocks : []));
    }
}
