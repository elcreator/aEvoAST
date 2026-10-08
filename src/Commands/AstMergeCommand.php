<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\IndexMeta;
use Elcreator\aEvoAST\Storage\Merger;

class AstMergeCommand extends Command
{
    protected $signature = 'ast:merge
        {--format= : Index format: bin (default), json or csv}
        {--output= : Custom output directory (default: project_root/.ast)}
        {--active-only : Write only active (non-overridden) chunks}';

    protected $description = 'Merge all cached symbol maps and embeddings into a unified project index';

    public function handle(): int
    {
        $format = $this->option('format') ?: config('aevoast.output.format', 'bin');
        $symbolFormat = $format === 'csv' ? 'csv' : 'json'; // symbol maps carry no vectors: readable JSON even beside a binary index
        $cachePath = config('aevoast.cache_path');
        $outputPath = $this->option('output')
            ?? base_path(config('aevoast.output.path', '.ast'));
        $activeOnly = $this->option('active-only');

        $writer = new FileWriter();
        $merger = new Merger();

        if (!is_dir($cachePath)) {
            $this->error("Cache directory not found: {$cachePath}");
            $this->info('Run `php artisan ast:parse` first.');
            return 1;
        }

        // Collect all cached source files
        $pattern = $cachePath . '/*.embeddings.' . $format;
        $files = glob($pattern);

        $symbolPattern = $cachePath . '/*.symbols.' . $symbolFormat;
        $symbolFiles = glob($symbolPattern);

        if (empty($files) && empty($symbolFiles)) {
            $this->error("No cached files found in {$cachePath}");
            $this->info('Run `php artisan ast:parse` first.');
            return 1;
        }

        // Merge embeddings
        if (!empty($files)) {
            $this->info('Merging embeddings from ' . count($files) . ' sources…');

            // One index = one vector space. Refuse to mix models.
            $spaces = [];
            foreach ($files as $file) {
                try {
                    $spaces[$file] = IndexMeta::read($file);
                } catch (\RuntimeException $e) {
                    $this->error(basename($file) . ': ' . $e->getMessage());
                    $this->info('Rebuild it with `php artisan ast:parse --force`, or fetch it again with `ast:pull`.');
                    return 1;
                }
            }
            $withVectors = array_values(array_filter($spaces, fn($m) => !IndexMeta::isLexicalOnly($m)));
            $reference = $withVectors[0] ?? IndexMeta::lexicalOnly();
            $mixed = array_filter($spaces, fn($m) => !IndexMeta::sameSpace($m, $reference));
            if ($mixed) {
                $this->error('Cached embeddings come from different models, they cannot be merged:');
                foreach ($spaces as $file => $meta) {
                    $this->line('  ' . basename($file) . '  ' . IndexMeta::label($meta));
                }
                $this->info('Re-run `php artisan ast:parse --force` with a single embedder.');
                return 1;
            }

            foreach ($files as $file) {
                $this->line('  Loading: ' . basename($file, ".embeddings.{$format}"));
            }

            // Streamed: two real indexes (core + docs) are more than PHP's default memory holds as arrays.
            $embFile = "{$outputPath}/merged.embeddings.{$format}";
            $overrideCount = 0;
            $total = 0;
            $stream = (function () use ($merger, $files, $writer, $activeOnly, &$overrideCount, &$total) {
                foreach ($merger->mergeFiles($files, $writer, $activeOnly, $overrideCount) as $chunk) {
                    $total++;
                    yield $chunk;
                }
            })();
            $mergedMeta = array_diff_key($reference, ['legacy' => 1, 'source' => 1, 'layer' => 1, 'version' => 1, 'lang' => 1]);
            $mergedMeta['created_at'] = gmdate('c');
            $writer->writeEmbeddingsStream($embFile, $stream, $format, $mergedMeta);

            $activeCount = $total - ($activeOnly ? 0 : $overrideCount);
            IndexMeta::write($embFile, $mergedMeta);

            $this->info("✓ Merged embeddings → {$embFile}");
            $this->line("  {$activeCount} active chunks, {$overrideCount} overridden");
        }

        // Merge symbol maps
        if (!empty($symbolFiles)) {
            $this->info('Merging symbol maps from ' . count($symbolFiles) . ' sources…');

            $allSymbols = [];
            foreach ($symbolFiles as $file) {
                $sourceName = basename($file, ".symbols.{$symbolFormat}");
                $this->line("  Loading: {$sourceName}");
                $symbols = $writer->readSymbols($file);
                $allSymbols = array_merge($allSymbols, $symbols);
            }

            $symFile = "{$outputPath}/merged.symbols.{$symbolFormat}";
            $writer->writeSymbols($symFile, $allSymbols, $symbolFormat);

            $this->info("✓ Merged symbols → {$symFile}");
            $this->line("  " . count($allSymbols) . " total symbols");
        }

        $this->newLine();
        $this->info('Merge complete.');

        return 0;
    }
}
