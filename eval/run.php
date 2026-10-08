<?php
/**
 * Retrieval quality on a gold set (eval/queries.json): does the right symbol or page come
 * back in the first results? Compares vector, lexical and hybrid search, with and without
 * docblock summaries, documentation and nomic task prefixes.
 *
 *   php eval/run.php --code=<core/src> [--docs=<evo-docs/en>] --ollama=http://localhost:11434 [--cache=<dir>]
 *
 * A dev tool, not a test: it needs Ollama and spends a few minutes embedding. Embeddings
 * are cached in --cache, keyed by variant, so a rerun only recomputes what changed.
 * "Relevant" for a code chunk = its id or text contains one of the query's `match` names.
 * For a documentation chunk = it comes from a page that mentions one of those names
 * (a page that documents the API is a good answer even where this paragraph does not
 * repeat the identifier).
 */
require __DIR__ . '/../vendor/autoload.php';

use Elcreator\aEvoAST\Embeddings\OllamaEmbedder;
use Elcreator\aEvoAST\Parsers\Chunker;
use Elcreator\aEvoAST\Parsers\MarkdownChunker;
use Elcreator\aEvoAST\Parsers\SymbolMapParser;
use Elcreator\aEvoAST\Search\HybridSearch;
use Elcreator\aEvoAST\Storage\FileWriter;

$opt = getopt('', ['code:', 'docs:', 'ollama::', 'cache::', 'model::', 'only::', 'prefix::']);
$codeDir = $opt['code'] ?? null;
$docsDir = $opt['docs'] ?? null;
$cache = rtrim($opt['cache'] ?? sys_get_temp_dir() . '/aast-eval', '/\\');
@mkdir($cache, 0777, true);
$embedder = new OllamaEmbedder($opt['ollama'] ?? 'http://localhost:11434', $opt['model'] ?? 'nomic-embed-text');
$writer = new FileWriter();
$queries = json_decode(file_get_contents(__DIR__ . '/queries.json'), true);

if (!$codeDir) {
    fwrite(STDERR, "--code=<dir> is required\n");
    exit(2);
}

$parsed = (new SymbolMapParser())->parseDirectory($codeDir, 'core', 'evolution-cms');
$symbolsPlain = $parsed['symbols'];
foreach ($symbolsPlain as &$sym) {
    unset($sym['summary']);
    foreach ($sym['methods'] ?? [] as &$m) {
        unset($m['summary']);
    }
    unset($m);
}
unset($sym);

$corpora = [
    'code'          => (new Chunker())->chunk($symbolsPlain, 'method'),
    'code+summary'  => (new Chunker())->chunk($parsed['symbols'], 'method'),
];
if ($docsDir) {
    $docs = (new MarkdownChunker())->chunkDirectory($docsDir, 'evo-docs', 'en');
    $corpora['code+summary+docs'] = array_merge($corpora['code+summary'], $docs);
    $corpora['docs'] = $docs;
}
if (!empty($opt['only'])) {
    $corpora = array_intersect_key($corpora, array_flip(explode(',', $opt['only'])));
}
$prefixVariants = ($opt['prefix'] ?? '1') === '0' ? [false] : [false, true];
foreach ($corpora as $name => $chunks) {
    fprintf(STDERR, "%-20s %d chunks\n", $name, count($chunks));
}

/** Embed chunks, reusing per-chunk vectors cached by variant + text hash. */
$embedAll = function (array $chunks, bool $prefix) use ($embedder, $cache): array {
    $file = fn(string $variant) => "$cache/vec-$variant.json";
    $store = [];
    $variant = ($prefix ? 'prefix' : 'plain');
    if (is_file($file($variant))) {
        $store = json_decode(file_get_contents($file($variant)), true) ?: [];
    }

    $todo = [];
    foreach ($chunks as $i => $c) {
        $key = sha1(($prefix ? 'D:' : '') . $c['text']);
        if (!isset($store[$key])) {
            $todo[$key] = ($prefix ? 'search_document: ' : '') . mb_substr($c['text'], 0, 2000);
        }
    }
    $keys = array_keys($todo);
    for ($i = 0; $i < count($keys); $i += 32) {
        $slice = array_slice($keys, $i, 32);
        $vecs = $embedder->embedBatch(array_map(fn($k) => $todo[$k], $slice));
        foreach ($slice as $j => $k) {
            $store[$k] = array_map(fn($x) => round($x, 6), $vecs[$j]);
        }
        if ($i % 640 === 0) {
            fprintf(STDERR, "  [%s] embedding %d/%d\n", $variant, $i, count($keys));
        }
    }
    if ($todo) {
        file_put_contents($file($variant), json_encode($store));
    }

    foreach ($chunks as $i => $c) {
        $chunks[$i]['embedding'] = $store[sha1(($prefix ? 'D:' : '') . $c['text'])];
    }

    return $chunks;
};

$queryVectors = [];
$vectorFor = function (string $q, bool $prefix) use ($embedder, &$queryVectors) {
    return $queryVectors[$prefix][$q] ??= $embedder->embed(($prefix ? 'search_query: ' : '') . $q);
};

// Pages of the docs that mention each name.
$pagesFor = [];
if ($docsDir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docsDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'md') {
            continue;
        }
        $body = file_get_contents($f->getPathname());
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($docsDir, '/\\')))), '/');
        foreach ($queries as $q) {
            foreach ($q['match'] as $name) {
                if (str_contains($body, $name)) {
                    $pagesFor[$name][$rel] = true;
                }
            }
        }
    }
}

$relevant = function (array $hit, array $match) use ($pagesFor): bool {
    foreach ($match as $name) {
        if (($hit['meta']['kind'] ?? '') === 'doc') {
            if (isset($pagesFor[$name][$hit['meta']['file']])) {
                return true;
            }
            continue;
        }
        if (str_contains($hit['id'], $name) || str_contains($hit['text'], $name)) {
            return true;
        }
    }

    return false;
};

$hybrid = new HybridSearch();
$rows = [];
foreach ($corpora as $corpus => $chunks) {
    foreach ($prefixVariants as $prefix) {
        $embedded = $embedAll($chunks, $prefix);
        foreach (['vector', 'lexical', 'hybrid', 'hybrid-v0.5', 'hybrid-l0.3'] as $mode) {
            if ($mode === 'lexical' && $prefix) {
                continue; // prefixes change vectors only
            }
            $vw = $mode === 'hybrid-v0.5' ? 0.5 : 1.0;
            $lw = $mode === 'hybrid-l0.3' ? 0.3 : 1.0;
            $searchMode = str_starts_with($mode, 'hybrid') ? 'hybrid' : $mode;
            $h1 = $h5 = $h10 = 0;
            $mrr = 0.0;
            $misses = [];
            foreach ($queries as $q) {
                $hits = $hybrid->search($q['q'], $vectorFor($q['q'], $prefix), $embedded, 10, true, [], $searchMode, $vw, $lw);
                $rank = null;
                foreach ($hits as $r => $hit) {
                    if ($relevant($hit, $q['match'])) {
                        $rank = $r + 1;
                        break;
                    }
                }
                if ($rank === null) {
                    $misses[] = $q['q'];
                    continue;
                }
                $h1 += $rank <= 1;
                $h5 += $rank <= 5;
                $h10 += $rank <= 10;
                $mrr += 1 / $rank;
            }
            $n = count($queries);
            $rows[] = [$corpus, $mode === 'lexical' ? '-' : ($prefix ? 'prefix' : 'plain'), $mode, $h1, $h5, $h10, round($mrr / $n, 3), $misses];
        }
    }
}

printf("%-18s %-7s %-8s %5s %5s %5s %6s   (of %d queries)\n", 'corpus', 'prefix', 'mode', 'hit@1', 'hit@5', 'hit@10', 'MRR', count($queries));
foreach ($rows as [$corpus, $prefix, $mode, $h1, $h5, $h10, $mrr]) {
    printf("%-18s %-7s %-8s %5d %5d %5d %6.3f\n", $corpus, $prefix, $mode, $h1, $h5, $h10, $mrr);
}
echo "\nMissed in the top 10, per row:\n";
foreach ($rows as [$corpus, $prefix, $mode, , , , , $misses]) {
    if ($misses) {
        printf("  %s / %s / %s: %s\n", $corpus, $prefix, $mode, implode('; ', $misses));
    }
}
