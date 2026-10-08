<?php

namespace Elcreator\aEvoAST\Pull;

use Elcreator\aEvoAST\Storage\IndexMeta;

/**
 * `manifest.json`, the list of ready-made indexes in a release. It comes from the network, so
 * every field that ends up in a path or a number is checked before anything uses it.
 */
final class Manifest
{
    public const FORMAT = 1;
    private const MAX_FILE_BYTES = 1073741824; // 1 GiB per file

    /**
     * @return array validated manifest
     *
     * @throws \RuntimeException
     */
    public static function validate(mixed $data): array
    {
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT || !is_array($data['sources'] ?? null)) {
            throw new \RuntimeException('The release manifest is not in a format this version of aEvoAST reads (update the package?)');
        }

        $sources = [];
        foreach ($data['sources'] as $i => $s) {
            $sources[] = self::source($s, $i);
        }

        return ['format' => self::FORMAT, 'evolution' => (string) ($data['evolution'] ?? ''), 'sources' => $sources];
    }

    /** The name a source's files are cached under (same rule as ast:parse). */
    public static function cacheKey(string $name, ?string $version): string
    {
        return str_replace(['/', '\\'], '_', $name) . ($version ? '_' . $version : '');
    }

    /**
     * What to install from a manifest, and what to leave and why.
     *
     * @param  array     $current  IndexMeta of the configured embedder (model, dimensions)
     * @param  string[]  $docLangs documentation languages wanted
     * @param  string|null $only   a single source name
     * @return array{install: array, skipped: array<int, array{source: string, reason: string}>}
     */
    public static function select(array $manifest, array $current, array $docLangs, ?string $only = null): array
    {
        $install = [];
        $skipped = [];

        foreach ($manifest['sources'] as $s) {
            if ($only !== null && $s['name'] !== $only) {
                continue;
            }
            if ($s['layer'] === 'docs' && !in_array($s['lang'], $docLangs, true)) {
                continue;
            }
            $label = $s['name'] . ($s['lang'] ? " ({$s['lang']})" : '');

            if (!IndexMeta::sameSpace($current, ['model' => $s['model'], 'dimensions' => $s['dimensions']])) {
                $skipped[] = ['source' => $label, 'reason' => "built with {$s['model']} ({$s['dimensions']}d), you use " . IndexMeta::label($current)];
                continue;
            }
            if ($s['chunks'] > IndexMeta::CHUNK_VERSION) {
                $skipped[] = ['source' => $label, 'reason' => 'made by a newer aEvoAST: update the package'];
                continue;
            }
            if ($s['chunks'] < IndexMeta::CHUNK_VERSION) {
                $skipped[] = ['source' => $label, 'reason' => 'made by an older aEvoAST: its chunks differ from the ones this version builds'];
                continue;
            }
            $install[] = $s;
        }

        return ['install' => $install, 'skipped' => $skipped];
    }

    private static function source(mixed $s, int|string $i): array
    {
        $bad = fn(string $what) => new \RuntimeException("The release manifest is invalid (source {$i}: {$what})");

        if (!is_array($s)) {
            throw $bad('not an object');
        }

        $name = $s['name'] ?? null;
        if (!is_string($name) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,80}$#', $name) || str_contains($name, '..')) {
            throw $bad('name');
        }
        $layer = $s['layer'] ?? null;
        if (!in_array($layer, ['core', 'extra', 'docs'], true)) {
            throw $bad('layer');
        }
        $version = $s['version'] ?? null;
        if ($version !== null && (!is_string($version) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,40}$/', $version))) {
            throw $bad('version');
        }
        $lang = $s['lang'] ?? null;
        if ($lang !== null && (!is_string($lang) || !preg_match('/^[a-z]{2,3}$/', $lang))) {
            throw $bad('lang');
        }
        $revision = $s['revision'] ?? null;
        if ($revision !== null && (!is_string($revision) || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $revision))) {
            throw $bad('revision');
        }
        $model = $s['model'] ?? null;
        if (!is_string($model) || $model === '' || strlen($model) > 100) {
            throw $bad('model');
        }
        foreach (['dimensions', 'chunks'] as $key) {
            if (!isset($s[$key]) || !is_int($s[$key]) || $s[$key] < 0 || $s[$key] > 65536) {
                throw $bad($key);
            }
        }

        $key = self::cacheKey($name, $version);
        $file = self::file($s['file'] ?? null, $key . '.embeddings.bin', $bad, 'file');
        $symbols = self::file($s['symbols'] ?? null, $key . '.symbols.json', $bad, 'symbols');

        return [
            'name' => $name, 'layer' => $layer, 'version' => $version, 'lang' => $lang, 'revision' => $revision,
            'model' => $model, 'dimensions' => $s['dimensions'], 'chunks' => $s['chunks'],
            'file' => $file, 'symbols' => $symbols,
        ];
    }

    /**
     * A file entry is only accepted under the exact name ast:parse would have cached it as: a manifest
     * cannot place a file anywhere else, nor replace one that belongs to another source.
     */
    private static function file(mixed $entry, string $expectedName, \Closure $bad, string $what): array
    {
        if (!is_array($entry)) {
            throw $bad($what);
        }
        if (($entry['name'] ?? null) !== $expectedName) {
            throw $bad("{$what} name must be {$expectedName}");
        }
        if (!is_string($entry['sha256'] ?? null) || !preg_match('/^[0-9a-f]{64}$/', $entry['sha256'])) {
            throw $bad("{$what} sha256");
        }
        $bytes = $entry['bytes'] ?? null;
        if (!is_int($bytes) || $bytes < 1 || $bytes > self::MAX_FILE_BYTES) {
            throw $bad("{$what} bytes");
        }

        return ['name' => $expectedName, 'sha256' => $entry['sha256'], 'bytes' => $bytes];
    }
}
