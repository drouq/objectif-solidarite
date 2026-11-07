<?php
declare(strict_types=1);

/**
 * Small Markdown to HTML converter for the content import (runs on a computer, never on the server).
 * It covers what the content package uses: ## and ### headings, paragraphs, - and 1. lists
 * (one level of nesting), > quotes, pipe tables, **bold**, *italic* or _italic_, [links](url)
 * and ![images](file). Raw HTML is never passed through: every "<" becomes text.
 * The result still goes through the site's Sanitizer, like anything an editor saves.
 */
final class Markdown
{
    /** @var callable|null fn(string $url): ?string — rewrites link addresses (null drops the link) */
    private $linkResolver;

    /** @var callable|null fn(string $file, string $alt): string — returns the <img> tag, or '' */
    private $imageResolver;

    public function __construct(?callable $linkResolver = null, ?callable $imageResolver = null)
    {
        $this->linkResolver = $linkResolver;
        $this->imageResolver = $imageResolver;
    }

    public function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = (string) preg_replace('/<!--.*?-->/s', '', $markdown);
        $lines = explode("\n", $markdown);
        return $this->blocks($lines);
    }

    /** @param string[] $lines */
    private function blocks(array $lines): string
    {
        $html = '';
        $paragraph = [];
        $count = count($lines);
        $flush = function () use (&$paragraph, &$html): void {
            if ($paragraph) {
                $html .= '<p>' . $this->inline(implode("\n", $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            if (trim($line) === '') {
                $flush();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $line, $m)) {
                $flush();
                $level = strlen($m[1]) <= 2 ? 2 : 3; // the page title is the only h1
                $html .= '<h' . $level . '>' . $this->inline($m[2]) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^\s{0,3}([-*_])(\s*\1){2,}\s*$/', $line)) {
                $flush(); // horizontal rule: the site has none, it only separates blocks
                continue;
            }
            if (preg_match('/^\s{0,3}>/', $line)) {
                $flush();
                $quote = [];
                while ($i < $count && preg_match('/^\s{0,3}>\s?(.*)$/u', $lines[$i], $q)) {
                    $quote[] = $q[1];
                    $i++;
                }
                $i--;
                $html .= '<blockquote>' . $this->blocks($quote) . '</blockquote>';
                continue;
            }
            if ($this->isTableStart($lines, $i)) {
                $flush();
                $rows = [];
                while ($i < $count && preg_match('/^\s*\|/', $lines[$i])) {
                    $rows[] = $lines[$i];
                    $i++;
                }
                $i--;
                $html .= $this->table($rows);
                continue;
            }
            if (preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $line, $m) && strlen($m[1]) < 4) {
                $flush();
                list($listHtml, $i) = $this->listBlock($lines, $i);
                $html .= $listHtml;
                continue;
            }
            $paragraph[] = trim($line);
        }
        $flush();
        return $html;
    }

    /** @param string[] $lines */
    private function isTableStart(array $lines, int $i): bool
    {
        return isset($lines[$i + 1])
            && preg_match('/^\s*\|.*\|\s*$/', $lines[$i])
            && preg_match('/^\s*\|(\s*:?-{1,}:?\s*\|)+\s*$/', $lines[$i + 1]);
    }

    /** @param string[] $rows */
    private function table(array $rows): string
    {
        $cells = function (string $row): array {
            $row = trim($row);
            $row = (string) preg_replace('/^\||\|$/', '', $row);
            return array_map('trim', explode('|', $row));
        };
        $head = $cells($rows[0]);
        $html = '<table><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th>' . $this->inline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($rows, 2) as $row) {
            $values = $cells($row);
            $html .= '<tr>';
            for ($c = 0; $c < count($head); $c++) {
                $html .= '<td>' . $this->inline($values[$c] ?? '') . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * A list starting at line $start. Items continue on lines indented by 2 or more spaces;
     * deeper list markers make a nested list.
     * @param string[] $lines
     * @return array{0:string,1:int} html and the index of the last line used
     */
    private function listBlock(array $lines, int $start): array
    {
        preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $lines[$start], $first);
        $indent = strlen($first[1]);
        $ordered = ctype_digit(substr($first[2], 0, 1));
        $items = [];
        $count = count($lines);
        $i = $start;
        for (; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            if (trim($line) === '') {
                // A blank line ends the list unless the next line continues it.
                $next = $lines[$i + 1] ?? '';
                if (preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $next, $nm) && strlen($nm[1]) === $indent
                    && ctype_digit(substr($nm[2], 0, 1)) === $ordered) {
                    continue;
                }
                break;
            }
            if (preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+(.*)$/u', $line, $m) && strlen($m[1]) === $indent) {
                if (ctype_digit(substr($m[2], 0, 1)) !== $ordered) {
                    break; // another kind of list starts
                }
                $items[] = [$m[3]];
                continue;
            }
            $lineIndent = strlen($line) - strlen(ltrim($line));
            if ($items && $lineIndent > $indent) {
                $items[count($items) - 1][] = substr($line, min($lineIndent, $indent + 2));
                continue;
            }
            break;
        }
        $tag = $ordered ? 'ol' : 'ul';
        $html = '<' . $tag . '>';
        foreach ($items as $itemLines) {
            $text = [array_shift($itemLines)];
            $nested = [];
            foreach ($itemLines as $extra) {
                if ($nested || preg_match('/^\s*([-*+]|\d{1,3}[.)])\s+/', $extra)) {
                    $nested[] = $extra;
                } else {
                    $text[] = trim($extra);
                }
            }
            $html .= '<li>' . $this->inline(implode("\n", $text)) . ($nested ? $this->blocks($nested) : '') . '</li>';
        }
        return [$html . '</' . $tag . '>', $i - 1];
    }

    /** Inline formatting. Text is escaped first; only the formats below become tags. */
    public function inline(string $text): string
    {
        $out = '';
        $pattern = '/(!?)\[((?:[^\[\]]|\[[^\[\]]*\])*)\]\(\s*([^()\s]+(?:\([^()\s]*\))?[^()\s]*)(?:\s+"[^"]*")?\s*\)/u';
        $offset = 0;
        while (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $out .= $this->emphasis(substr($text, $offset, $m[0][1] - $offset));
            $isImage = $m[1][0] === '!';
            $label = $m[2][0];
            $url = $m[3][0];
            if ($isImage) {
                $out .= $this->imageResolver !== null ? (string) call_user_func($this->imageResolver, $url, $label) : '';
            } else {
                $href = $this->linkResolver !== null ? call_user_func($this->linkResolver, $url) : $url;
                if ($href === null || $href === '') {
                    $out .= $this->emphasis($label);
                } else {
                    $class = in_array($url, Sanitizer::HREF_PLACEHOLDERS, true) ? ' class="btn"' : '';
                    $out .= '<a href="' . htmlspecialchars((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"' . $class . '>'
                        . $this->emphasis($label) . '</a>';
                }
            }
            $offset = $m[0][1] + strlen($m[0][0]);
        }
        $out .= $this->emphasis(substr($text, $offset));
        return (string) preg_replace('/\s*\n\s*/', ' ', $out);
    }

    private function emphasis(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/us', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__(?=\S)(.+?)(?<=\S)__/us', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/us', '<em>$1</em>', $text);
        $text = (string) preg_replace('/(?<![\w])_(?=\S)(.+?)(?<=\S)_(?![\w])/us', '<em>$1</em>', $text);
        return $text;
    }
}
