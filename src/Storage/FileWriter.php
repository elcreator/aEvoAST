<?php

namespace Elcreator\aEvoAST\Storage;

class FileWriter
{
    /**
     * Write symbol map (no embeddings) to file.
     */
    public function writeSymbols(string $path, array $symbols, string $format = 'json'): void
    {
        $this->ensureDir(dirname($path));

        match ($format) {
            'csv'   => $this->writeSymbolsCsv($path, $symbols),
            default => $this->writeJson($path, $symbols),
        };
    }

    /**
     * Write chunks with embeddings to file.
     *
     * @param array $chunks Each chunk has 'id', 'text', 'meta', 'embedding'
     */
    public function writeEmbeddings(string $path, array $chunks, string $format = 'json', ?array $meta = null): void
    {
        $this->ensureDir(dirname($path));

        match ($format) {
            'csv'   => $this->writeEmbeddingsCsv($path, $chunks),
            'bin'   => BinaryIndex::write($path, $chunks, $this->binaryMeta($meta)),
            default => $this->writeJsonLines($path, $chunks),
        };
    }

    /**
     * Read embeddings from a previously generated file.
     *
     * @return array<int, array{id: string, text: string, meta: array, embedding: float[]}>
     */
    public function readEmbeddings(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'csv'   => $this->readEmbeddingsCsv($path),
            'bin'   => iterator_to_array(BinaryIndex::iterate($path), false),
            default => $this->readJsonLines($path),
        };
    }

    /** A binary index records its model in its own header, so it cannot be written without saying which. */
    private function binaryMeta(?array $meta): array
    {
        if ($meta === null) {
            throw new \InvalidArgumentException('A binary index needs its metadata (model, dimensions): pass IndexMeta::describe() or IndexMeta::lexicalOnly()');
        }

        return $meta;
    }

    /**
     * Write chunks as they are produced; a merge of two real indexes does not fit in memory otherwise.
     *
     * @param iterable $chunks
     */
    public function writeEmbeddingsStream(string $path, iterable $chunks, string $format = 'json', ?array $meta = null): void
    {
        $this->ensureDir(dirname($path));

        if ($format === 'bin') {
            BinaryIndex::write($path, $chunks, $this->binaryMeta($meta));
            return;
        }

        if ($format === 'csv') {
            $this->writeEmbeddingsCsv($path, is_array($chunks) ? $chunks : iterator_to_array($chunks, false));
            return;
        }

        $this->writeJsonLines($path, $chunks);
    }

    /**
     * Read chunks one at a time. The CSV and older pretty-printed layouts are read whole.
     *
     * @return \Generator<int, array>
     */
    public function iterateEmbeddings(string $path): \Generator
    {
        if (!file_exists($path)) {
            return;
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);
        if ($ext === 'csv') {
            yield from $this->readEmbeddingsCsv($path);
            return;
        }
        if ($ext === 'bin') {
            yield from BinaryIndex::iterate($path);
            return;
        }

        yield from $this->iterateJsonLines($path);
    }

    /**
     * Read symbol map from a previously generated file.
     */
    public function readSymbols(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return match ($ext) {
            'csv'   => $this->readSymbolsCsv($path),
            default => $this->readJson($path),
        };
    }

    // ---------------------------------------------------------------
    // JSON
    // ---------------------------------------------------------------

    private function writeJson(string $path, array $data): void
    {
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * A JSON array with one element per line. Still one valid JSON document, but it is
     * written and read a chunk at a time: pretty-printing the embeddings of Evo's own core
     * (2.7k chunks x 768 floats) as one string ran out of PHP's default 128 MB.
     */
    private function writeJsonLines(string $path, iterable $items): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open for writing: {$path}");
        }

        fwrite($fp, "[\n");
        $first = true;
        foreach ($items as $item) {
            fwrite($fp, ($first ? '' : ",\n") . json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $first = false;
        }
        fwrite($fp, ($first ? '' : "\n") . "]\n");
        fclose($fp);
    }

    /** Reads the line-per-chunk layout, or any other JSON document (older indexes were pretty-printed). */
    private function readJsonLines(string $path): array
    {
        return iterator_to_array($this->iterateJsonLines($path), false);
    }

    private function iterateJsonLines(string $path): \Generator
    {
        $fp = fopen($path, 'r');
        if ($fp === false) {
            return;
        }

        $streamed = trim((string) fgets($fp)) === '[';
        $count = 0;
        while ($streamed && ($line = fgets($fp)) !== false) {
            $line = rtrim($line, ",\r\n ");
            if ($line === ']' || $line === '') {
                continue;
            }
            $item = $line[0] === '{' ? json_decode($line, true) : null;
            if (!is_array($item)) {
                $streamed = false; // a pretty-printed document, not our layout
                break;
            }
            $count++;
            yield $item;
        }
        fclose($fp);

        if (!$streamed) {
            // Not one chunk per line: an older, pretty-printed index. Nothing was yielded yet
            // unless the first line looked right, which a pretty-printed one never does.
            if ($count > 0) {
                throw new \RuntimeException("Unreadable embeddings file: {$path}");
            }
            yield from $this->readJson($path);
        }
    }

    private function readJson(string $path): array
    {
        $content = file_get_contents($path);
        return json_decode($content, true) ?? [];
    }

    // ---------------------------------------------------------------
    // CSV — embeddings
    // ---------------------------------------------------------------

    private function writeEmbeddingsCsv(string $path, array $chunks): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open for writing: {$path}");
        }

        // Header
        $metaKeys = $this->collectMetaKeys($chunks);
        $header = array_merge(['id', 'text'], $metaKeys, ['embedding']);
        fputcsv($fp, $header);

        foreach ($chunks as $chunk) {
            $row = [
                $chunk['id'],
                $chunk['text'],
            ];
            foreach ($metaKeys as $key) {
                $val = $chunk['meta'][$key] ?? '';
                $row[] = is_bool($val) ? ($val ? '1' : '0') : (string) $val;
            }
            // Embedding as semicolon-separated floats (commas conflict with CSV)
            $row[] = isset($chunk['embedding'])
                ? implode(';', $chunk['embedding'])
                : '';

            fputcsv($fp, $row);
        }

        fclose($fp);
    }

    private function readEmbeddingsCsv(string $path): array
    {
        $fp = fopen($path, 'r');
        if ($fp === false) return [];

        $header = fgetcsv($fp);
        if ($header === false) {
            fclose($fp);
            return [];
        }

        $embIdx = array_search('embedding', $header);
        $idIdx  = array_search('id', $header);
        $txtIdx = array_search('text', $header);

        // Meta keys = everything that isn't id, text, embedding
        $metaKeys = array_values(array_diff($header, ['id', 'text', 'embedding']));

        $chunks = [];
        while (($row = fgetcsv($fp)) !== false) {
            $meta = [];
            foreach ($metaKeys as $key) {
                $colIdx = array_search($key, $header);
                if ($colIdx !== false) {
                    $meta[$key] = $row[$colIdx] ?? '';
                }
            }

            $embString = $row[$embIdx] ?? '';
            $embedding = $embString !== ''
                ? array_map('floatval', explode(';', $embString))
                : [];

            $chunks[] = [
                'id'        => $row[$idIdx] ?? '',
                'text'      => $row[$txtIdx] ?? '',
                'meta'      => $meta,
                'embedding' => $embedding,
            ];
        }

        fclose($fp);
        return $chunks;
    }

    // ---------------------------------------------------------------
    // CSV — symbol maps (no embeddings)
    // ---------------------------------------------------------------

    private function writeSymbolsCsv(string $path, array $symbols): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open for writing: {$path}");
        }

        fputcsv($fp, [
            'kind', 'fqcn', 'name', 'namespace', 'extends', 'implements',
            'file', 'source', 'layer', 'line_start', 'line_end',
            'methods_count', 'properties_count',
        ]);

        foreach ($symbols as $sym) {
            $extends = $sym['extends'] ?? '';
            if (is_array($extends)) $extends = implode(', ', $extends);

            fputcsv($fp, [
                $sym['kind'] ?? '',
                $sym['fqcn'] ?? $sym['name'] ?? '',
                $sym['name'] ?? '',
                $sym['namespace'] ?? '',
                $extends,
                implode(', ', $sym['implements'] ?? []),
                $sym['file'] ?? '',
                $sym['source'] ?? '',
                $sym['layer'] ?? '',
                $sym['line_start'] ?? 0,
                $sym['line_end'] ?? 0,
                count($sym['methods'] ?? []),
                count($sym['properties'] ?? []),
            ]);
        }

        fclose($fp);
    }

    private function readSymbolsCsv(string $path): array
    {
        $fp = fopen($path, 'r');
        if ($fp === false) return [];

        $header = fgetcsv($fp);
        $symbols = [];
        while (($row = fgetcsv($fp)) !== false) {
            $sym = [];
            foreach ($header as $i => $key) {
                $sym[$key] = $row[$i] ?? '';
            }
            $symbols[] = $sym;
        }

        fclose($fp);
        return $symbols;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function collectMetaKeys(array $chunks): array
    {
        $keys = [];
        foreach ($chunks as $chunk) {
            foreach (array_keys($chunk['meta'] ?? []) as $k) {
                $keys[$k] = true;
            }
        }
        return array_keys($keys);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
