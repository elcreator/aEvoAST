<?php

namespace Elcreator\aEvoAST\Parsers;

/**
 * Documentation (markdown) into chunks: one per section, headed by where it sits
 * ("Developers > API > Clear the site cache > Example"), so a hit says what it is
 * without opening the file. Headings inside code fences are code, not headings.
 */
class MarkdownChunker
{
    public function __construct(private int $maxChars = 1800, private int $minChars = 40)
    {
    }

    /**
     * @return array<int, array{id: string, text: string, meta: array}>
     */
    public function chunkDirectory(string $root, string $source, string $lang = 'en'): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        $chunks = [];
        foreach ($files as $file) {
            $relative = ltrim(str_replace('\\', '/', substr($file, strlen(rtrim($root, '/\\')))), '/');
            foreach ($this->chunkFile((string) file_get_contents($file), $relative, $source, $lang) as $c) {
                $chunks[] = $c;
            }
        }

        return $chunks;
    }

    /**
     * @return array<int, array{id: string, text: string, meta: array}>
     */
    public function chunkFile(string $markdown, string $relativePath, string $source, string $lang = 'en'): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $markdown = preg_replace('/\A---\n.*?\n---\n/s', '', $markdown); // front matter

        $context = $this->pathContext($relativePath);
        $headings = [];   // level => title
        $body = [];
        $startLine = 1;
        $sections = [];   // [headingPath, body, line]
        $fence = false;

        $flush = function () use (&$headings, &$body, &$startLine, &$sections) {
            $text = trim(implode("\n", $body));
            if ($text !== '') {
                $sections[] = [implode(' > ', $headings), $text, $startLine, $headings ? end($headings) : ''];
            }
            $body = [];
        };

        foreach (explode("\n", $markdown) as $n => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fence = !$fence;
            }
            if (!$fence && preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m)) {
                $flush();
                $level = strlen($m[1]);
                foreach (array_keys($headings) as $l) {
                    if ($l >= $level) {
                        unset($headings[$l]);
                    }
                }
                $headings[$level] = trim($m[2]);
                $startLine = $n + 1;
                continue;
            }
            $body[] = $line;
        }
        $flush();

        $chunks = [];
        $i = 0;
        foreach ($sections as [$headingPath, $text, $line, $last]) {
            foreach ($this->split($text) as $piece) {
                if (strlen(trim($piece)) < $this->minChars) {
                    continue;
                }
                $title = trim($context . ($headingPath !== '' ? ' > ' . $headingPath : ''), ' >');
                $chunks[] = [
                    'id'   => "{$source}::{$relativePath}#" . ++$i,
                    'text' => $title . "\n" . trim($piece),
                    'meta' => [
                        'kind'       => 'doc',
                        'name'       => mb_substr($last !== '' ? $last : basename($relativePath, '.md'), 0, 120),
                        'heading'    => $title,
                        'file'       => $relativePath,
                        'source'     => $source,
                        'layer'      => 'docs',
                        'lang'       => LanguageGuess::of($piece),
                        'lang_folder' => $lang,
                        'line_start' => $line,
                    ],
                ];
            }
        }

        return $chunks;
    }

    /** "03_Developers/10_API/Clear_cache.md" => "Developers > API > Clear cache" */
    private function pathContext(string $relativePath): string
    {
        $parts = explode('/', preg_replace('/\.md$/i', '', $relativePath));
        $parts = array_values(array_filter(array_map(function (string $p) {
            $p = preg_replace('/^\d+[_\-\s]*/', '', $p);

            return trim(str_replace('_', ' ', $p));
        }, $parts), fn($p) => $p !== '' && strtolower($p) !== 'index'));

        return implode(' > ', $parts);
    }

    /** Split an over-long section at blank lines, never inside a code fence. @return string[] */
    private function split(string $text): array
    {
        if (strlen($text) <= $this->maxChars) {
            return [$text];
        }

        $pieces = [];
        $current = '';
        $fence = false;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fence = !$fence;
            }
            if (!$fence && trim($line) === '' && strlen($current) >= $this->maxChars / 2) {
                $pieces[] = $current;
                $current = '';
                continue;
            }
            $current .= $line . "\n";
            if (strlen($current) >= $this->maxChars * 1.5) {
                $pieces[] = $current;
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }
}
