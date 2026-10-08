<?php

namespace Elcreator\aEvoAST\Pull;

use Elcreator\aEvoAST\Embeddings\ChunkEmbedder;
use Elcreator\aEvoAST\Embeddings\Embedder;
use Elcreator\aEvoAST\Parsers\SourceIndexer;
use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\IndexMeta;

/**
 * Builds the files of an index release: for each source its symbol map and binary index, and the
 * manifest that `ast:pull` reads. Run by the publishing workflow (`bin/build-release.php`), never on a
 * user's machine.
 */
final class ReleaseBuilder
{
    public function __construct(
        private SourceIndexer $indexer = new SourceIndexer(),
        private ChunkEmbedder $embedService = new ChunkEmbedder(),
        private FileWriter $writer = new FileWriter(),
    ) {
    }

    /**
     * @param  array[]        $sources  each: path, layer, name, and optionally version, lang, revision
     * @param  callable|null  $log      fn(string $line): void
     * @return array the manifest that was written
     *
     * @throws \RuntimeException when anything fails: a half-built release is worse than none
     */
    public function build(array $sources, Embedder $embedder, string $outDir, string $evolution, int $batchSize = 32, ?callable $log = null): array
    {
        $log ??= static function (string $line): void {};
        if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException("Cannot create {$outDir}");
        }

        $entries = [];
        foreach ($sources as $source) {
            $source += ['version' => null, 'lang' => null, 'revision' => null];
            $log("Indexing {$source['name']} ({$source['layer']})…");

            $indexed = $this->indexer->index($source);
            if ($indexed['chunks'] === []) {
                throw new \RuntimeException("{$source['name']}: nothing to index in {$source['path']}");
            }

            $log('  ' . count($indexed['chunks']) . ' chunks, embedding with ' . $embedder->getModel() . '…');
            $chunks = $this->embedService->embed($indexed['chunks'], $embedder, $batchSize, function (int $done, int $total) use ($log) {
                if ($done === $total || $done % 640 === 0) {
                    $log("  {$done}/{$total}");
                }
            }, strict: true);

            $key = Manifest::cacheKey($source['name'], $source['version']);
            $symbolsName = "{$key}.symbols.json";
            $indexName = "{$key}.embeddings.bin";

            $this->writer->writeSymbols("{$outDir}/{$symbolsName}", $indexed['symbols'], 'json');
            $meta = IndexMeta::describe($embedder) + array_filter([
                'source' => $source['name'], 'layer' => $source['layer'],
                'version' => $source['version'], 'lang' => $source['lang'],
            ], fn($v) => $v !== null);
            $this->writer->writeEmbeddings("{$outDir}/{$indexName}", $chunks, 'bin', $meta);

            $entries[] = [
                'name'       => $source['name'],
                'layer'      => $source['layer'],
                'version'    => $source['version'],
                'lang'       => $source['lang'],
                'revision'   => $source['revision'],
                'model'      => $embedder->getModel(),
                'dimensions' => $embedder->getDimensions(),
                'chunks'     => IndexMeta::CHUNK_VERSION,
                'file'       => $this->describeFile($outDir, $indexName),
                'symbols'    => $this->describeFile($outDir, $symbolsName),
            ];
            $log('  ' . $indexName . ' ' . number_format($entries[array_key_last($entries)]['file']['bytes'] / 1048576, 1) . ' MB');
        }

        $manifest = ['format' => Manifest::FORMAT, 'evolution' => $evolution, 'generated_at' => gmdate('c'), 'sources' => $entries];
        Manifest::validate($manifest); // what we publish must be what we would accept
        file_put_contents("{$outDir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $manifest;
    }

    private function describeFile(string $dir, string $name): array
    {
        return ['name' => $name, 'sha256' => hash_file('sha256', "{$dir}/{$name}"), 'bytes' => filesize("{$dir}/{$name}")];
    }
}
