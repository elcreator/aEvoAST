<?php

namespace Elcreator\aEvoAST\Pull;

use Elcreator\aEvoAST\Storage\BinaryIndex;

/**
 * Downloads the selected indexes of a release into the cache, where `ast:merge` finds them as if
 * `ast:parse` had made them. Nothing reaches the cache before it is checked: size and sha256 against the
 * manifest, then the file itself against the manifest's claims (model, size of the vectors, chunk version).
 */
final class Puller
{
    public const REGISTRY = 'pulled.json';

    public function __construct(private ReleaseClient $client, private string $cacheDir)
    {
    }

    /**
     * @param  array   $release   the release as GitHub describes it
     * @param  array[] $sources   Manifest::select()['install']
     * @return array<int, array{source: string, status: 'installed'|'up-to-date'|'failed', detail: string}>
     */
    public function install(array $release, array $sources, string $tag): array
    {
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException("Cannot create the cache directory: {$this->cacheDir}");
        }

        $results = [];
        foreach ($sources as $s) {
            $label = $s['name'] . ($s['lang'] ? " ({$s['lang']})" : '');
            try {
                $changed = false;
                foreach (['symbols', 'file'] as $part) {
                    $changed = $this->fetch($release, $s, $s[$part], $part === 'file') || $changed;
                }
                $this->remember($s, $tag);
                $results[] = ['source' => $label, 'status' => $changed ? 'installed' : 'up-to-date', 'detail' => $s['file']['name']];
            } catch (\RuntimeException $e) {
                $results[] = ['source' => $label, 'status' => 'failed', 'detail' => $e->getMessage()];
            }
        }

        return $results;
    }

    /** @return bool true when the file was (re)installed */
    private function fetch(array $release, array $source, array $file, bool $isIndex): bool
    {
        $dest = $this->cacheDir . '/' . $file['name'];

        if (is_file($dest) && hash_file('sha256', $dest) === $file['sha256']) {
            return false;
        }

        $part = $dest . '.part';
        try {
            $this->client->download($release, $file['name'], $part, $file['bytes']);

            if (filesize($part) !== $file['bytes']) {
                throw new \RuntimeException("{$file['name']} is not the size the manifest promises");
            }
            if (hash_file('sha256', $part) !== $file['sha256']) {
                throw new \RuntimeException("{$file['name']} does not match its checksum: not installed");
            }

            $isIndex ? $this->checkIndex($part, $source) : $this->checkSymbols($part, $file['name']);

            if (!rename($part, $dest)) {
                throw new \RuntimeException("Cannot move {$file['name']} into the cache");
            }
        } finally {
            @unlink($part);
        }

        return true;
    }

    /** The file has to be what the manifest says it is, or the manifest is wrong about it. */
    private function checkIndex(string $path, array $source): void
    {
        $h = BinaryIndex::header($path);

        if (($h['model'] ?? null) !== $source['model'] || $h['dimensions'] !== $source['dimensions'] || ($h['chunks'] ?? null) !== $source['chunks']) {
            throw new \RuntimeException("{$source['name']}: the index does not match what the manifest says about it");
        }
        if (!$h['hasVectors']) {
            throw new \RuntimeException("{$source['name']}: the published index has no vectors");
        }
    }

    private function checkSymbols(string $path, string $name): void
    {
        if (!is_array(json_decode((string) file_get_contents($path), true))) {
            throw new \RuntimeException("{$name} is not a valid symbol map");
        }
    }

    private function remember(array $source, string $tag): void
    {
        $path = $this->cacheDir . '/' . self::REGISTRY;
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

        $all[$source['file']['name']] = [
            'tag' => $tag, 'sha256' => $source['file']['sha256'], 'model' => $source['model'],
            'version' => $source['version'], 'pulled_at' => gmdate('c'),
        ];
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
