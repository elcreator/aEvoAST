<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Parsers\SymbolMapParser;
use Elcreator\aEvoAST\Parsers\Chunker;
use Elcreator\aEvoAST\Parsers\MarkdownChunker;
use Elcreator\aEvoAST\Parsers\SourceDiscovery;
use Elcreator\aEvoAST\Parsers\SourceIndexer;
use Elcreator\aEvoAST\Embeddings\ChunkEmbedder;
use Elcreator\aEvoAST\Embeddings\Embedder;
use Elcreator\aEvoAST\Embeddings\EmbedderFactory;
use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\IndexMeta;

class AstParseCommand extends Command
{
    protected $signature = 'ast:parse
        {--source= : Parse only a specific source by name (e.g. "evolution-cms", "seiger/slang")}
        {--layer= : Parse only a specific layer: core, extra, local or docs}
        {--path= : Parse a specific directory path with --layer and --name}
        {--name= : Source name when using --path}
        {--lang=en : Language of a documentation source given with --path --layer=docs}
        {--format= : Index format: bin (default, compact), json or csv}
        {--no-embeddings : Skip embedding generation: symbol maps and a keyword-only index}
        {--force : Regenerate even if cache exists}';

    protected $description = 'Parse Evolution CMS sources and documentation into symbol maps, chunks and embeddings';

    public function handle(
        SymbolMapParser $parser,
        Embedder        $embedder,
        SourceDiscovery $discovery,
    ): int {
        $format = $this->option('format') ?: config('aevoast.output.format', 'bin');
        $symbolFormat = $format === 'csv' ? 'csv' : 'json'; // symbol maps carry no vectors: readable JSON even beside a binary index
        $noEmbeddings = $this->option('no-embeddings');
        $force = $this->option('force');
        $cachePath = config('aevoast.cache_path');
        $chunkBy = config('aevoast.chunk_by', 'method');
        $batchSize = config('aevoast.batch_size', 32);

        $writer = new FileWriter();
        $indexer = new SourceIndexer($parser, new Chunker(), new MarkdownChunker());
        $embedService = new ChunkEmbedder();

        // Determine sources to parse
        if ($this->option('path')) {
            $sources = [[
                'path'    => $this->option('path'),
                'layer'   => $this->option('layer') ?? 'local',
                'name'    => $this->option('name') ?? basename($this->option('path')),
                'version' => null,
                'lang'    => $this->option('lang') ?: 'en',
            ]];
        } elseif ($this->option('source')) {
            $all = $discovery->discover(base_path());
            $sources = array_filter($all, fn($s) => $s['name'] === $this->option('source'));
            if (empty($sources)) {
                $this->error("Source '{$this->option('source')}' not found.");
                return 1;
            }
        } elseif ($this->option('layer')) {
            $all = $discovery->discover(base_path());
            $sources = array_filter($all, fn($s) => $s['layer'] === $this->option('layer'));
        } else {
            $sources = $discovery->discover(base_path());
        }

        if (empty($sources)) {
            $this->warn('No sources found to parse.');
            return 1;
        }

        // Check the embedder if we need embeddings
        if (!$noEmbeddings && !$embedder->isAvailable()) {
            $this->warn($embedder->getDriver() . ' embedder is not available for model "' . $embedder->getModel() . '".');
            $this->warn($embedder->getDriver() === 'ollama'
                ? 'Run: ollama pull ' . $embedder->getModel()
                : 'Check aevoast.openai.url / key (AST_OPENAI_URL, AST_OPENAI_KEY).');
            $this->info('Continuing without embeddings: keyword search (ast:search --mode=lexical) still works.');
            $noEmbeddings = true;
        }

        $mayEmbedLocal = EmbedderFactory::mayEmbedLocal($embedder, config('aevoast'));

        $totalSymbols = 0;
        $totalChunks = 0;

        foreach ($sources as $source) {
            $safeName = str_replace(['/', '\\'], '_', $source['name']);
            $versionSuffix = $source['version'] ? '_' . $source['version'] : '';
            $cacheKey = "{$safeName}{$versionSuffix}";

            $symbolsFile = "{$cachePath}/{$cacheKey}.symbols.{$symbolFormat}";
            $embeddingsFile = "{$cachePath}/{$cacheKey}.embeddings.{$format}";

            // Remote embedders do not see the local layer (your own code) unless allowed.
            $sourceEmbeds = !$noEmbeddings && ($source['layer'] !== 'local' || $mayEmbedLocal);
            if (!$noEmbeddings && !$sourceEmbeds) {
                $this->warn("  {$source['name']}: local code is not sent to a remote embedder (aevoast.allow_remote_local); keyword index only.");
            }

            // Check cache; cached embeddings count only when this model made them.
            if (!$force && file_exists($symbolsFile) && file_exists($embeddingsFile)) {
                try {
                    $existing = IndexMeta::read($embeddingsFile);
                } catch (\RuntimeException) {
                    $existing = null; // unreadable: rebuild it
                }
                $hasVectors = $existing !== null && !IndexMeta::isLexicalOnly($existing);
                $fresh = $existing !== null && IndexMeta::isCurrent($existing) && ($sourceEmbeds
                    ? $hasVectors && IndexMeta::sameSpace($existing, IndexMeta::describe($embedder))
                    : true);

                if ($fresh) {
                    $this->line("  <comment>cached</comment>  {$source['name']} → {$embeddingsFile}");
                    continue;
                }
                if ($hasVectors) {
                    $this->line('  cached embeddings are ' . IndexMeta::label($existing) . ", regenerating for {$embedder->getModel()}");
                }
            }

            $this->info("Parsing: {$source['name']} ({$source['layer']})");
            $this->line("  Path: {$source['path']}");

            $indexed = $indexer->index($source, $chunkBy);
            $symbols = $indexed['symbols'];
            $chunks = $indexed['chunks'];

            foreach (array_slice($indexed['errors'], 0, 5) as $err) {
                $this->warn("  ⚠ {$err}");
            }
            if (count($indexed['errors']) > 5) {
                $this->warn('  … and ' . (count($indexed['errors']) - 5) . ' more errors');
            }
            $this->line('  Found ' . count($symbols) . ($source['layer'] === 'docs' ? ' documentation sections' : ' symbols'));

            $totalSymbols += count($symbols);

            // Write symbol map
            $writer->writeSymbols($symbolsFile, $symbols, $symbolFormat);
            $this->info("  ✓ Symbols → {$symbolsFile}");

            // Embeddings, when wanted. The chunks are written either way: keyword search needs them.
            $chunkCount = count($chunks);
            if ($sourceEmbeds) {
                $totalChunks += $chunkCount;

                $this->line("  Embedding {$chunkCount} chunks…");

                $bar = $this->output->createProgressBar($chunkCount);
                $bar->start();

                $failures = 0;
                $embedded = $embedService->embed($chunks, $embedder, $batchSize, function (int $done, int $total, ?string $error) use ($bar, &$failures) {
                    if ($error !== null) {
                        $failures++;
                        $this->newLine();
                        $this->error("  Embedding failed ({$error})");
                    }
                    $bar->setProgress($done);
                });
                $chunks = $embedded;

                $bar->finish();
                $this->newLine();
                if ($failures > 0) {
                    $this->warn("  {$failures} batch(es) failed; their chunks are searchable by keywords only.");
                }
            }

            $meta = $sourceEmbeds ? IndexMeta::describe($embedder) : IndexMeta::lexicalOnly();
            $writer->writeEmbeddings($embeddingsFile, $chunks, $format, $meta);
            IndexMeta::write($embeddingsFile, $meta);
            $this->info("  ✓ " . ($sourceEmbeds ? 'Embeddings' : 'Chunks (keyword only)') . " → {$embeddingsFile}");
        }

        $this->newLine();
        $this->info("Done. {$totalSymbols} symbols" .
            ($totalChunks ? ", {$totalChunks} chunks embedded" : '') . '.');

        return 0;
    }
}
