<?php

namespace Elcreator\aEvoAST\Storage;

/**
 * The binary index file (`*.embeddings.bin`): small to download, quick to read, readable in
 * constant memory.
 *
 *   8 bytes   magic "AEAST\0" + format version + "\0"
 *   4 bytes   header length (uint32 LE)
 *   n bytes   header, JSON: model, dimensions, chunk version, count, which rows have no vector ...
 *   m bytes   gzip of the chunks, one JSON object per line: {"id", "text", "meta"}
 *   v bytes   the vectors: count x dimensions float32 (LE), row i belongs to chunk i
 *
 * The header says how long every part is, and a file whose parts do not add up to its size is
 * refused: these files are downloaded, so nothing in them is trusted until it is checked.
 */
final class BinaryIndex
{
    public const FORMAT = 1;

    private const MAGIC = "AEAST\0\1\0";
    private const MAX_HEADER_BYTES = 1048576;        // 1 MiB
    private const MAX_META_BYTES = 536870912;        // 512 MiB once decompressed
    private const MAX_DIMENSIONS = 16384;

    /**
     * @param  iterable  $chunks  each: id, text, meta, embedding (a chunk with none is allowed)
     * @param  array     $meta    IndexMeta fields (model, dimensions, ...); extra keys go into the header
     *
     * @throws \RuntimeException
     */
    public static function write(string $path, iterable $chunks, array $meta): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }

        $wantVectors = !IndexMeta::isLexicalOnly($meta);
        $dimensions = $wantVectors ? (int) ($meta['dimensions'] ?? 0) : 0;

        $metaTmp = tempnam($dir, 'aeast-m');
        $vecTmp = tempnam($dir, 'aeast-v');
        $out = $path . '.part';

        try {
            $metaFp = gzopen($metaTmp, 'wb6');
            $vecFp = fopen($vecTmp, 'wb');
            if ($metaFp === false || $vecFp === false) {
                throw new \RuntimeException("Cannot write in {$dir}");
            }

            $count = 0;
            $missing = [];
            $seenVector = false;
            $pendingZeroRows = 0; // rows before the first vector, when the size is not known yet

            foreach ($chunks as $chunk) {
                gzwrite($metaFp, json_encode([
                    'id'   => $chunk['id'],
                    'text' => $chunk['text'],
                    'meta' => $chunk['meta'] ?? [],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");

                if ($wantVectors) {
                    $vector = $chunk['embedding'] ?? [];
                    if ($vector === []) {
                        $missing[] = $count;
                        if ($dimensions > 0) {
                            fwrite($vecFp, str_repeat("\0", 4 * $dimensions));
                        } else {
                            $pendingZeroRows++;
                        }
                    } else {
                        if ($dimensions === 0) {
                            $dimensions = count($vector);
                            fwrite($vecFp, str_repeat("\0", 4 * $dimensions * $pendingZeroRows));
                            $pendingZeroRows = 0;
                        }
                        if (count($vector) !== $dimensions) {
                            throw new \RuntimeException(sprintf(
                                'Chunk "%s" has a vector of %d numbers, the index has %d', $chunk['id'], count($vector), $dimensions
                            ));
                        }
                        fwrite($vecFp, pack('g' . $dimensions, ...$vector));
                        $seenVector = true;
                    }
                }
                $count++;
            }
            gzclose($metaFp);
            fclose($vecFp);

            $hasVectors = $wantVectors && $seenVector;
            if (!$hasVectors) {
                $missing = [];
                $dimensions = 0;
                file_put_contents($vecTmp, '');
            }

            $header = array_merge(array_diff_key($meta, ['legacy' => 1]), [
                'format'      => self::FORMAT,
                'count'       => $count,
                'dimensions'  => $dimensions,
                'hasVectors'  => $hasVectors,
                'missing'     => $missing,
                'metaBytes'   => filesize($metaTmp),
                'vectorBytes' => filesize($vecTmp),
            ]);
            if (!$hasVectors) {
                // no vectors at all: this is a keyword-only index whatever the caller hoped for
                $header['model'] = '';
                $header['driver'] = '';
            }
            $json = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $fp = fopen($out, 'wb');
            if ($fp === false) {
                throw new \RuntimeException("Cannot write {$out}");
            }
            fwrite($fp, self::MAGIC . pack('V', strlen($json)) . $json);
            foreach ([$metaTmp, $vecTmp] as $part) {
                $in = fopen($part, 'rb');
                stream_copy_to_stream($in, $fp);
                fclose($in);
            }
            fclose($fp);

            if (!rename($out, $path)) {
                throw new \RuntimeException("Cannot move the index into place: {$path}");
            }
        } finally {
            @unlink($metaTmp);
            @unlink($vecTmp);
            @unlink($out);
        }
    }

    /**
     * Read and check the header (and that the parts add up to the file's size). Cheap: it does
     * not touch the chunks.
     *
     * @throws \RuntimeException when the file is not a valid index
     */
    public static function header(string $path): array
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        try {
            return self::readHeader($fp, $path, filesize($path));
        } finally {
            fclose($fp);
        }
    }

    /**
     * @return \Generator<int, array{id: string, text: string, meta: array, embedding: float[]}>
     *
     * @throws \RuntimeException when the file is not a valid index
     */
    public static function iterate(string $path): \Generator
    {
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            throw new \RuntimeException("Cannot read {$path}");
        }

        try {
            $header = self::readHeader($fp, $path, filesize($path));

            $packed = '';
            if ($header['metaBytes'] > 0) {
                $packed = fread($fp, $header['metaBytes']);
            }
            $text = $packed === '' ? '' : @gzdecode($packed, self::MAX_META_BYTES);
            if ($text === false) {
                throw new \RuntimeException("Corrupt index (the chunks do not decompress): {$path}");
            }
            unset($packed);

            $lines = $text === '' ? [] : explode("\n", rtrim($text, "\n"));
            unset($text);
            if (count($lines) !== $header['count']) {
                throw new \RuntimeException("Corrupt index (the header counts {$header['count']} chunks, the file holds " . count($lines) . "): {$path}");
            }

            $missing = array_flip($header['missing']);
            $dims = $header['dimensions'];
            $rowBytes = 4 * $dims;

            foreach ($lines as $i => $line) {
                $chunk = json_decode($line, true);
                if (!is_array($chunk) || !isset($chunk['id'], $chunk['text'])) {
                    throw new \RuntimeException("Corrupt index (chunk {$i} is not valid): {$path}");
                }
                $chunk['meta'] = is_array($chunk['meta'] ?? null) ? $chunk['meta'] : [];

                $chunk['embedding'] = [];
                if ($header['hasVectors']) {
                    $row = fread($fp, $rowBytes);
                    if (strlen($row) !== $rowBytes) {
                        throw new \RuntimeException("Corrupt index (vectors end early): {$path}");
                    }
                    if (!isset($missing[$i])) {
                        $chunk['embedding'] = array_values(unpack('g' . $dims, $row));
                    }
                }

                yield $i => $chunk;
            }
        } finally {
            fclose($fp);
        }
    }

    /** @param resource $fp */
    private static function readHeader($fp, string $path, int $fileSize): array
    {
        $magic = fread($fp, 8);
        if ($magic !== self::MAGIC) {
            throw new \RuntimeException("Not an aEvoAST index (or a newer format than this version reads): {$path}");
        }

        $lenBytes = (string) fread($fp, 4);
        $len = strlen($lenBytes) === 4 ? unpack('V', $lenBytes)[1] : 0;
        if ($len < 2 || $len > self::MAX_HEADER_BYTES) {
            throw new \RuntimeException("Corrupt index (header length): {$path}");
        }

        $json = fread($fp, $len);
        $h = strlen($json) === $len ? json_decode($json, true) : null;
        if (!is_array($h) || ($h['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException("Corrupt index (header): {$path}");
        }

        foreach (['count', 'dimensions', 'metaBytes', 'vectorBytes'] as $key) {
            if (!isset($h[$key]) || !is_int($h[$key]) || $h[$key] < 0) {
                throw new \RuntimeException("Corrupt index (header field {$key}): {$path}");
            }
        }
        $h['hasVectors'] = (bool) ($h['hasVectors'] ?? false);
        $h['missing'] = is_array($h['missing'] ?? null) ? array_values(array_filter($h['missing'], 'is_int')) : [];

        if ($h['dimensions'] > self::MAX_DIMENSIONS) {
            throw new \RuntimeException("Corrupt index (dimensions): {$path}");
        }
        $expectedVectors = $h['hasVectors'] ? $h['count'] * $h['dimensions'] * 4 : 0;
        if ($h['vectorBytes'] !== $expectedVectors) {
            throw new \RuntimeException("Corrupt index (vector size): {$path}");
        }
        if (12 + $len + $h['metaBytes'] + $h['vectorBytes'] !== $fileSize) {
            throw new \RuntimeException("Corrupt index (truncated or padded): {$path}");
        }

        return $h;
    }
}
