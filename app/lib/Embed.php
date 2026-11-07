<?php
declare(strict_types=1);

/** Video embeds. Only YouTube (privacy-enhanced domain) and Vimeo are accepted. */
final class Embed
{
    /**
     * Read a video address. Returns null if it is not an accepted YouTube or Vimeo link.
     * @return array{provider:string,id:string,hash:string}|null
     */
    public static function parse(string $url): ?array
    {
        $url = trim($url);
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $host = (string) preg_replace('/^(www\.|m\.)/', '', $host);
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $youtubeId = null;
        if ($host === 'youtu.be') {
            $youtubeId = trim($path, '/');
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
            if ($path === '/watch' && isset($query['v']) && is_string($query['v'])) {
                $youtubeId = $query['v'];
            } elseif (preg_match('~^/(embed|shorts|live|v)/([^/?#]+)~', $path, $m)) {
                $youtubeId = $m[2];
            }
        }
        if ($youtubeId !== null) {
            if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $youtubeId)) {
                return null;
            }
            return ['provider' => 'youtube', 'id' => $youtubeId, 'hash' => ''];
        }

        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            $hash = '';
            if (isset($query['h']) && is_string($query['h']) && preg_match('/^[a-f0-9]{6,20}$/i', $query['h'])) {
                $hash = $query['h'];
            }
            if (preg_match('~^/(?:video/)?(\d{5,12})(?:/([a-f0-9]{6,20}))?/?$~i', $path, $m)
                || preg_match('~^/(?:channels/[\w-]+|groups/[\w-]+/videos)/(\d{5,12})/?$~i', $path, $m)) {
                if ($hash === '' && isset($m[2]) && $m[2] !== '') {
                    $hash = $m[2];
                }
                return ['provider' => 'vimeo', 'id' => $m[1], 'hash' => $hash];
            }
        }
        return null;
    }

    /** Address of the player loaded in the iframe. */
    public static function playerUrl(array $video): string
    {
        if ($video['provider'] === 'youtube') {
            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($video['id']) . '?rel=0';
        }
        $url = 'https://player.vimeo.com/video/' . rawurlencode($video['id']) . '?dnt=1';
        if ($video['hash'] !== '') {
            $url .= '&h=' . rawurlencode($video['hash']);
        }
        return $url;
    }

    public static function providerName(array $video): string
    {
        return $video['provider'] === 'youtube' ? 'YouTube' : 'Vimeo';
    }
}
