<?php

namespace Elcreator\aEvoAST\Storage;

class Merger
{
    /**
     * Layer priority: higher number = higher priority.
     */
    private const LAYER_PRIORITY = [
        'core'  => 0,
        'extra' => 1,
        'local' => 2,
    ];

    /**
     * Merge multiple chunk sets, tracking overrides.
     *
     * Each chunk must have 'id', 'text', 'meta' (with 'layer'), and optionally 'embedding'.
     *
     * When two chunks share the same class+method (or function fqcn),
     * the higher-layer chunk wins, but the lower-layer chunk is kept
     * with meta.overridden = true.
     *
     * @param  array<int, array> $chunkSets  Multiple arrays of chunks
     * @return array  Merged chunks
     */
    public function merge(array $chunkSets): array
    {
        $all = [];
        foreach ($chunkSets as $chunks) {
            foreach ($chunks as $chunk) {
                $all[] = $chunk;
            }
        }

        // Group by semantic key (class::method or function fqcn)
        $groups = [];
        foreach ($all as $chunk) {
            $key = $this->semanticKey($chunk);
            $groups[$key][] = $chunk;
        }

        $merged = [];
        foreach ($groups as $key => $candidates) {
            if (count($candidates) === 1) {
                $chunk = $candidates[0];
                $chunk['meta']['overridden'] = false;
                $chunk['meta']['overridden_by'] = null;
                $merged[] = $chunk;
                continue;
            }

            // Sort by layer priority descending
            usort($candidates, fn($a, $b) =>
                $this->layerPriority($b) <=> $this->layerPriority($a)
            );

            $winner = $candidates[0];
            $winner['meta']['overridden'] = false;
            $winner['meta']['overridden_by'] = null;
            $merged[] = $winner;

            // Keep losers marked as overridden
            for ($i = 1; $i < count($candidates); $i++) {
                $loser = $candidates[$i];
                $loser['meta']['overridden'] = true;
                $loser['meta']['overridden_by'] = $winner['meta']['source'] ?? 'unknown';
                $merged[] = $loser;
            }
        }

        return $merged;
    }

    /**
     * The same merge as merge(), file by file, in constant memory: one pass over the files
     * to see which chunk wins each symbol, a second to yield every chunk with its flags.
     * Output follows the input order instead of grouping a symbol's versions together.
     *
     * @param  string[]  $files
     * @param  int|null  $overridden  set to the number of overridden chunks seen
     * @return \Generator<int, array>
     */
    public function mergeFiles(array $files, FileWriter $reader, bool $activeOnly = false, ?int &$overridden = null): \Generator
    {
        $files = array_values($files);
        $overridden = 0;

        $best = [];
        foreach ($files as $fi => $file) {
            $n = 0;
            foreach ($reader->iterateEmbeddings($file) as $chunk) {
                $key = $this->semanticKey($chunk);
                $priority = $this->layerPriority($chunk);
                if (!isset($best[$key]) || $priority > $best[$key]['p']) {
                    $best[$key] = ['p' => $priority, 'src' => $chunk['meta']['source'] ?? 'unknown', 'file' => $fi, 'n' => $n];
                }
                $n++;
            }
        }

        foreach ($files as $fi => $file) {
            $n = 0;
            foreach ($reader->iterateEmbeddings($file) as $chunk) {
                $winner = $best[$this->semanticKey($chunk)];
                $wins = $winner['file'] === $fi && $winner['n'] === $n;
                $n++;

                if (!$wins) {
                    $overridden++;
                    if ($activeOnly) {
                        continue;
                    }
                }
                $chunk['meta']['overridden'] = !$wins;
                $chunk['meta']['overridden_by'] = $wins ? null : $winner['src'];

                yield $chunk;
            }
        }
    }

    /**
     * Get only active (non-overridden) chunks.
     */
    public function active(array $merged): array
    {
        return array_values(array_filter(
            $merged,
            fn($chunk) => !($chunk['meta']['overridden'] ?? false)
        ));
    }

    /**
     * Build a semantic key that identifies a unique symbol.
     */
    private function semanticKey(array $chunk): string
    {
        $meta = $chunk['meta'] ?? [];
        $kind = $meta['kind'] ?? 'unknown';

        if ($kind === 'method') {
            return 'method::' . ($meta['class'] ?? '') . '::' . ($meta['method'] ?? '');
        }

        if ($kind === 'function') {
            return 'function::' . ($meta['fqcn'] ?? $meta['name'] ?? '');
        }

        // A documentation section is identified by where it is, never by its heading:
        // a hundred pages have an "Example" section and none overrides another.
        if ($kind === 'doc') {
            return 'doc::' . ($chunk['id'] ?? '');
        }

        // class / interface / trait / enum header
        return $kind . '::' . ($meta['fqcn'] ?? $meta['name'] ?? '');
    }

    private function layerPriority(array $chunk): int
    {
        $layer = $chunk['meta']['layer'] ?? 'core';
        return self::LAYER_PRIORITY[$layer] ?? 0;
    }
}
