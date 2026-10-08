<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Embeddings\Embedder;
use Elcreator\aEvoAST\Search\HybridSearch;
use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\IndexMeta;
use Elcreator\aEvoAST\Storage\VectorSearch;

class AstSearchCommand extends Command
{
    protected $signature = 'ast:search
        {query : Natural language search query}
        {--top=10 : Number of results}
        {--mode=hybrid : hybrid (keywords + vectors), vector, or lexical (keywords only, needs no embedder)}
        {--format= : Index format to read: bin (default), json or csv}
        {--source= : Filter by source name}
        {--layer= : Filter by layer: core, extra, local, docs}
        {--lang= : Only documentation written in this language: en, ru or uk (code is always searched)}
        {--include-overridden : Include overridden symbols in results}
        {--input= : Custom path to embeddings file}';

    protected $description = 'Search the merged index: keywords and vectors, code and documentation';

    public function handle(Embedder $embedder): int
    {
        $query = $this->argument('query');
        $topK = (int) $this->option('top');
        $format = $this->option('format') ?: config('aevoast.output.format', 'bin');
        $mode = $this->option('mode');

        if (!in_array($mode, HybridSearch::MODES, true)) {
            $this->error('--mode must be one of: ' . implode(', ', HybridSearch::MODES));
            return 1;
        }

        $inputFile = $this->option('input')
            ?? base_path(config('aevoast.output.path', '.ast')) . "/merged.embeddings.{$format}";

        if (!file_exists($inputFile)) {
            $this->error("Embeddings file not found: {$inputFile}");
            $this->info('Run `php artisan ast:parse` then `php artisan ast:merge` first.');
            return 1;
        }

        // What the index can do, and what this machine can do about the query.
        try {
            $indexMeta = IndexMeta::read($inputFile);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            $this->info('Rebuild it with `php artisan ast:parse --force && php artisan ast:merge`, or fetch a fresh one with `ast:pull`.');
            return 1;
        }
        $queryVector = null;

        if ($mode !== 'lexical') {
            if (IndexMeta::isLexicalOnly($indexMeta)) {
                if ($mode === 'vector') {
                    $this->error('This index has no vectors. Rebuild with an embedder, or use --mode=lexical.');
                    return 1;
                }
                $mode = 'lexical';
            } else {
                $current = IndexMeta::describe($embedder);
                if (!IndexMeta::sameSpace($indexMeta, $current)) {
                    $this->error('The index was built with ' . IndexMeta::label($indexMeta)
                        . ' but the configured embedder is ' . IndexMeta::label($current) . '.');
                    $this->info('Set aevoast.embedder to match, rebuild with `ast:parse --force && ast:merge`, or use --mode=lexical.');
                    return 1;
                }

                if ($embedder->isAvailable()) {
                    try {
                        $queryVector = $embedder->embed($query);
                    } catch (\Throwable $e) {
                        $queryVector = null;
                        $this->warn('Could not embed the query: ' . $e->getMessage());
                    }
                }

                if ($queryVector === null) {
                    if ($mode === 'vector') {
                        $this->error('The ' . $embedder->getDriver() . ' embedder is not available. Cannot embed the query.');
                        return 1;
                    }
                    $this->warn('Falling back to keyword search.');
                    $mode = 'lexical';
                }
            }
        }

        // Stream the index: score each vector as it is read and keep only the text. Held as PHP
        // arrays, the vectors of Evo's core and docs alone are more than the default 128 MB.
        $chunks = [];
        $scores = [];
        $cosine = new VectorSearch();
        foreach ((new FileWriter())->iterateEmbeddings($inputFile) as $i => $chunk) {
            if ($queryVector !== null && !empty($chunk['embedding'])) {
                $scores[$i] = $cosine->cosineSimilarity($queryVector, $chunk['embedding']);
            }
            unset($chunk['embedding']);
            $chunks[$i] = $chunk;
        }
        $this->line('Searching ' . count($chunks) . " chunks ({$mode})…");

        $filterMeta = [];
        if ($this->option('source')) {
            $filterMeta['source'] = $this->option('source');
        }
        if ($this->option('layer')) {
            $filterMeta['layer'] = $this->option('layer');
        }

        // The en/ docs folder is partly Russian: let people ask for what they can read.
        if ($this->option('lang')) {
            $wanted = strtolower($this->option('lang'));
            $chunks = array_filter($chunks, fn($c) => ($c['meta']['layer'] ?? '') !== 'docs' || ($c['meta']['lang'] ?? 'en') === $wanted);
        }

        // Code and documentation answer different questions and compete badly in one list
        // (a page of prose would push the method that does the work off the page): two lists.
        $isDoc = fn(array $c) => ($c['meta']['layer'] ?? '') === 'docs';
        $groups = ['' => $chunks];
        if (empty($filterMeta['layer'])) {
            $docs = array_filter($chunks, $isDoc);   // keys kept: they index $scores
            $code = array_filter($chunks, fn($c) => !$isDoc($c));
            if ($docs && $code) {
                $groups = ['Code' => $code, 'Documentation' => $docs];
            }
        }

        $search = new HybridSearch();
        $shown = 0;
        foreach ($groups as $title => $group) {
            $results = $search->search(
                $query,
                $queryVector,
                $group,
                $topK,
                activeOnly: !$this->option('include-overridden'),
                filterMeta: $filterMeta,
                mode: $mode,
                vectorWeight: (float) config('aevoast.search.vector_weight', 1.0),
                lexicalWeight: (float) config('aevoast.search.lexical_weight', 0.3),
                vectorScores: $queryVector !== null ? $scores : null,
            );
            if ($results) {
                $shown += count($results);
                $this->printResults($results, $query, $topK, $title);
            }
        }

        if ($shown === 0) {
            $this->warn('No results found.');
        }

        return 0;
    }

    private function printResults(array $results, string $query, int $topK, string $title): void
    {
        $this->newLine();
        $this->info(($title !== '' ? "{$title}: " : '') . "top {$topK} results for: \"{$query}\"");
        $this->newLine();

        foreach ($results as $i => $r) {
            $rank = $i + 1;
            $source = $r['meta']['source'] ?? '?';
            $layer = $r['meta']['layer'] ?? '?';
            $file = $r['meta']['file'] ?? '';
            $overridden = ($r['meta']['overridden'] ?? false) ? ' [OVERRIDDEN]' : '';
            $language = ($layer === 'docs' && ($r['meta']['lang'] ?? 'en') !== 'en') ? ' in ' . strtoupper($r['meta']['lang']) : '';

            $parts = [];
            if ($r['vector'] !== null) {
                $parts[] = 'vector ' . round($r['vector'], 3);
            }
            if ($r['lexical'] !== null) {
                $parts[] = 'keywords ' . round($r['lexical'], 2);
            }

            $this->line("<info>#{$rank}</info> (" . implode(', ', $parts) . ") [{$layer}:{$source}{$language}]{$overridden}");
            $this->line('   ' . str_replace("\n", "\n   ", $this->excerpt($r['text'])));
            if ($file) {
                $this->line("   <comment>{$file}</comment>");
            }
            $this->newLine();
        }
    }

    /** A code chunk is one line; a documentation chunk can be pages. */
    private function excerpt(string $text): string
    {
        $lines = explode("\n", $text);
        $head = implode("\n", array_slice($lines, 0, 6));

        return mb_strlen($head) > 500 ? mb_substr($head, 0, 500) . '…' : $head . (count($lines) > 6 ? "\n…" : '');
    }
}
