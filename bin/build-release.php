#!/usr/bin/env php
<?php
/**
 * Builds the files of an index release (see .github/workflows/publish-index.yml):
 *
 *   php bin/build-release.php --evolution=<Evolution CMS checkout> [--docs=<docs checkout>] --out=<dir>
 *                             [--langs=en,ru] [--ollama=http://localhost:11434] [--model=bge-m3]
 *
 * Writes <dir>/manifest.json plus one symbol map and one binary index per source, and prints the
 * release tag (index-<Evolution CMS version>) as the last line. Needs a running Ollama with the model.
 * Exit codes: 0 done, 1 failed, 2 bad usage.
 */

use Elcreator\aEvoAST\Embeddings\OllamaEmbedder;
use Elcreator\aEvoAST\Pull\ReleaseBuilder;
use Elcreator\aEvoAST\Pull\ReleaseClient;

require __DIR__ . '/../vendor/autoload.php';

$opt = getopt('', ['evolution:', 'docs::', 'out:', 'langs::', 'ollama::', 'model::', 'batch::']);
if (empty($opt['evolution']) || empty($opt['out'])) {
    fwrite(STDERR, "usage: php bin/build-release.php --evolution=<checkout> [--docs=<checkout>] --out=<dir> [--langs=en] [--ollama=url] [--model=bge-m3]\n");
    exit(2);
}

$evolution = rtrim($opt['evolution'], '/\\');
$versionFile = $evolution . '/core/factory/version.php';
$version = is_file($versionFile) ? (include $versionFile)['version'] ?? null : null;
if (!is_string($version) || !preg_match('/^[0-9]+(\.[0-9]+)*$/', $version)) {
    fwrite(STDERR, "Cannot read the Evolution CMS version from {$versionFile}\n");
    exit(2);
}
if (!is_dir($evolution . '/core/src')) {
    fwrite(STDERR, "No core/src in {$evolution}\n");
    exit(2);
}

$sources = [[
    'path' => $evolution . '/core/src', 'layer' => 'core', 'name' => 'evolution-cms', 'version' => $version,
]];

if (!empty($opt['docs'])) {
    $docs = rtrim($opt['docs'], '/\\');
    $revision = null;
    if (is_dir($docs . '/.git')) {
        $out = [];
        exec('git -C ' . escapeshellarg($docs) . ' rev-parse --short=10 HEAD 2>&1', $out, $code);
        $revision = $code === 0 && preg_match('/^[0-9a-f]{7,40}$/', $out[0] ?? '') ? $out[0] : null;
    }
    foreach (array_filter(array_map('trim', explode(',', $opt['langs'] ?? 'en'))) as $lang) {
        if (!preg_match('/^[a-z]{2,3}$/', $lang) || !is_dir("{$docs}/{$lang}")) {
            fwrite(STDERR, "No documentation folder for language \"{$lang}\" in {$docs}\n");
            exit(2);
        }
        $sources[] = [
            'path' => "{$docs}/{$lang}", 'layer' => 'docs', 'lang' => $lang, 'revision' => $revision,
            'name' => $lang === 'en' ? 'evo-docs' : "evo-docs-{$lang}",
        ];
    }
}

$embedder = new OllamaEmbedder($opt['ollama'] ?? 'http://localhost:11434', $opt['model'] ?? 'bge-m3');
if (!$embedder->isAvailable()) {
    fwrite(STDERR, 'Ollama is not reachable, or model "' . $embedder->getModel() . "\" is not pulled\n");
    exit(1);
}

try {
    $manifest = (new ReleaseBuilder())->build(
        $sources, $embedder, $opt['out'], $version, (int) ($opt['batch'] ?? 32),
        fn(string $line) => fwrite(STDERR, $line . "\n"),
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDERR, count($manifest['sources']) . " sources written to {$opt['out']}\n");
echo ReleaseClient::TAG_PREFIX . $version . "\n";
