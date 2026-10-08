<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Parsers\SourceDiscovery;
use Elcreator\aEvoAST\Embeddings\Embedder;
use Elcreator\aEvoAST\Storage\IndexMeta;

class AstStatusCommand extends Command
{
    protected $signature = 'ast:status';
    protected $description = 'Show status of AST sources, cache, and Ollama';

    public function handle(SourceDiscovery $discovery, Embedder $embedder): int
    {
        $cachePath = config('aevoast.cache_path');
        $outputPath = base_path(config('aevoast.output.path', '.ast'));
        $format = config('aevoast.output.format', 'bin');
        $symbolFormat = $format === 'csv' ? 'csv' : 'json';

        // Ollama status
        $this->info('Embedder');
        $this->line("  Driver: " . $embedder->getDriver() . ($embedder->isRemote() ? ' (remote)' : ' (local)'));
        $this->line("  Model:  " . $embedder->getModel());
        $this->line("  Status: " . ($embedder->isAvailable() ? '<info>✓ available</info>' : '<error>✗ not available</error>'));
        $this->newLine();

        // Discovered sources
        $sources = $discovery->discover(base_path());
        $this->info('Discovered Sources');

        if (empty($sources)) {
            $this->warn('  No sources found.');
        } else {
            $rows = [];
            foreach ($sources as $s) {
                $safeName = str_replace(['/', '\\'], '_', $s['name']);
                $versionSuffix = $s['version'] ? '_' . $s['version'] : '';
                $cacheKey = "{$safeName}{$versionSuffix}";

                $hasSym = file_exists("{$cachePath}/{$cacheKey}.symbols.{$symbolFormat}");
                $hasEmb = file_exists("{$cachePath}/{$cacheKey}.embeddings.{$format}");

                $rows[] = [
                    $s['layer'],
                    $s['name'],
                    $s['version'] ?? '-',
                    $hasSym ? '✓' : '✗',
                    $hasEmb ? '✓' : '✗',
                ];
            }

            $this->table(
                ['Layer', 'Source', 'Version', 'Symbols', 'Embeddings'],
                $rows
            );
        }

        // Merged index status
        $this->newLine();
        $this->info('Merged Index');

        $mergedSym = "{$outputPath}/merged.symbols.{$symbolFormat}";
        $mergedEmb = "{$outputPath}/merged.embeddings.{$format}";

        $this->line("  Symbols:    " . (file_exists($mergedSym)
            ? '<info>✓</info> ' . $this->fileSize($mergedSym)
            : '<comment>not generated</comment>'));

        $this->line("  Embeddings: " . (file_exists($mergedEmb)
            ? '<info>✓</info> ' . $this->fileSize($mergedEmb)
            : '<comment>not generated</comment>'));

        if (file_exists($mergedEmb)) {
            $this->line('  Model:      ' . IndexMeta::label(IndexMeta::read($mergedEmb)));
        }

        // Cache dir size
        $this->newLine();
        $this->info('Cache');
        $this->line("  Path: {$cachePath}");
        if (is_dir($cachePath)) {
            $files = glob("{$cachePath}/*");
            $totalSize = array_sum(array_map('filesize', $files));
            $this->line("  Files: " . count($files));
            $this->line("  Size:  " . $this->humanSize($totalSize));
        } else {
            $this->line("  <comment>Not created yet</comment>");
        }

        return 0;
    }

    private function fileSize(string $path): string
    {
        return $this->humanSize(filesize($path));
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < 3) {
            $size /= 1024;
            $i++;
        }
        return round($size, 1) . ' ' . $units[$i];
    }
}
