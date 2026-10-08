<?php

namespace Elcreator\aEvoAST\Storage;

use Elcreator\aEvoAST\Embeddings\Embedder;

/**
 * Sidecar `<embeddings file>.meta.json`: which vector space an index lives in.
 *
 * Vectors from different models cannot be compared, so a cache, a merge or a search that
 * mixes them is refused. The chunk files themselves are unchanged, so indexes written
 * before this existed still load; they are taken to be nomic-embed-text.
 */
final class IndexMeta
{
    /**
     * Bump when the text or metadata of a chunk changes (v2: docblock summaries, documentation chunks;
     * v3: the language of a documentation chunk is detected, not taken from its folder),
     * so caches made by an older chunker are rebuilt instead of trusted.
     */
    public const CHUNK_VERSION = 3;

    public const LEGACY = ['driver' => 'ollama', 'model' => 'nomic-embed-text', 'dimensions' => 768, 'legacy' => true];

    public static function path(string $embeddingsFile): string
    {
        return $embeddingsFile . '.meta.json';
    }

    public static function isBinary(string $file): bool
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'bin';
    }

    /** A chunk file without vectors: keyword search only. Compatible with every model. */
    public static function lexicalOnly(): array
    {
        return ['driver' => '', 'model' => '', 'dimensions' => 0, 'chunks' => self::CHUNK_VERSION, 'created_at' => gmdate('c')];
    }

    public static function isLexicalOnly(array $meta): bool
    {
        return trim((string) ($meta['model'] ?? '')) === '';
    }

    public static function describe(Embedder $embedder): array
    {
        return [
            'driver'     => $embedder->getDriver(),
            'model'      => $embedder->getModel(),
            'dimensions' => $embedder->getDimensions(),
            'chunks'     => self::CHUNK_VERSION,
            'created_at' => gmdate('c'),
        ];
    }

    public static function write(string $embeddingsFile, array $meta): void
    {
        if (self::isBinary($embeddingsFile)) {
            return; // a binary index carries its own header
        }

        file_put_contents(
            self::path($embeddingsFile),
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /** Sidecar contents; LEGACY when the file has none; null when there is no index file. */
    public static function read(string $embeddingsFile): ?array
    {
        if (!file_exists($embeddingsFile)) {
            return null;
        }

        if (self::isBinary($embeddingsFile)) {
            // The header is the truth; there is no sidecar to disagree with it.
            return array_intersect_key(BinaryIndex::header($embeddingsFile), array_flip(
                ['driver', 'model', 'dimensions', 'chunks', 'created_at', 'source', 'layer', 'version', 'lang']
            )) + ['driver' => '', 'model' => '', 'dimensions' => 0];
        }

        $path = self::path($embeddingsFile);
        if (!file_exists($path)) {
            return self::LEGACY;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && array_key_exists('model', $data) ? $data : self::LEGACY;
    }

    /** Was this chunk file written by the current chunker? Files without the field predate it. */
    public static function isCurrent(array $meta): bool
    {
        return (int) ($meta['chunks'] ?? 1) >= self::CHUNK_VERSION;
    }

    /** "nomic-embed-text:latest" and "nomic-embed-text" are the same model. */
    public static function baseModel(string $model): string
    {
        return preg_replace('/:latest$/', '', trim($model));
    }

    /** Same vector space? An unknown (0) size matches any; the model decides. */
    public static function sameSpace(array $a, array $b): bool
    {
        if (self::isLexicalOnly($a) || self::isLexicalOnly($b)) {
            return true; // no vectors, nothing to compare
        }

        if (self::baseModel((string) $a['model']) !== self::baseModel((string) $b['model'])) {
            return false;
        }

        $da = (int) ($a['dimensions'] ?? 0);
        $db = (int) ($b['dimensions'] ?? 0);

        return $da === 0 || $db === 0 || $da === $db;
    }

    public static function label(array $meta): string
    {
        if (self::isLexicalOnly($meta)) {
            return 'no vectors (keyword only)';
        }

        $dims = (int) ($meta['dimensions'] ?? 0);

        return $meta['model'] . ($dims ? " ({$dims}d)" : '') . (($meta['legacy'] ?? false) ? ' [no metadata, assumed]' : '');
    }
}
