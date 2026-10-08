<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Embeddings\Embedder;
use Elcreator\aEvoAST\Pull\Manifest;
use Elcreator\aEvoAST\Pull\Puller;
use Elcreator\aEvoAST\Pull\ReleaseBuilder;
use Elcreator\aEvoAST\Pull\ReleaseClient;
use Elcreator\aEvoAST\Storage\BinaryIndex;
use Elcreator\aEvoAST\Storage\IndexMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PullTest extends TestCase
{
    private string $root;
    private string $dist;
    private string $cache;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aast-pull-' . bin2hex(random_bytes(4));
        $this->dist = $this->root . '/dist';
        $this->cache = $this->root . '/cache';
        foreach (['src', 'docs/en'] as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
        file_put_contents($this->root . '/src/Core.php', '<?php namespace Evo; class Core { /** Clear the cache of MODX. */ public function clearCache() {} public function runSnippet($n) {} }');
        file_put_contents($this->root . '/docs/en/Cache.md', "# Cache\n\nCall clearCache() to empty the site cache, it takes no parameters at all.\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function build(?Embedder $embedder = null): array
    {
        return (new ReleaseBuilder())->build([
            ['path' => $this->root . '/src', 'layer' => 'core', 'name' => 'evolution-cms', 'version' => '3.5.9'],
            ['path' => $this->root . '/docs/en', 'layer' => 'docs', 'name' => 'evo-docs', 'lang' => 'en', 'revision' => '83d4310'],
        ], $embedder ?? new FakeEmbedder(), $this->dist, '3.5.9');
    }

    /** A "GitHub" that serves the built dist/ directory. */
    private function client(array &$calls = [], ?callable $tamper = null): ReleaseClient
    {
        $assets = [];
        foreach (glob($this->dist . '/*') as $f) {
            $assets[] = ['name' => basename($f), 'browser_download_url' => 'https://github.com/elcreator/aEvoAST/releases/download/index-3.5.9/' . basename($f)];
        }
        $release = ['tag_name' => 'index-3.5.9', 'assets' => $assets];

        $get = function (string $url, array $headers) use (&$calls, $release): array {
            $calls[] = $url;
            if (str_ends_with($url, '/releases/tags/index-3.5.9')) {
                return [200, json_encode($release)];
            }
            if (str_ends_with($url, '/releases/tags/index-9.9.9')) {
                return [404, '{}'];
            }
            if (str_contains($url, '/releases?per_page')) {
                return [200, json_encode([
                    ['tag_name' => 'v1.0.0'], ['tag_name' => 'index-3.5.8'], ['tag_name' => 'index-3.5.10'],
                    ['tag_name' => 'index-3.5.9'], ['tag_name' => 'index-4.0.0', 'prerelease' => true],
                ])];
            }
            if (str_ends_with($url, '/manifest.json')) {
                return [200, file_get_contents($this->dist . '/manifest.json')];
            }

            return [404, ''];
        };
        $download = function (string $url, array $headers, string $dest, int $max) use (&$calls, $tamper): int {
            $calls[] = $url;
            $src = $this->dist . '/' . basename($url);
            if (!is_file($src)) {
                return 404;
            }
            $bytes = file_get_contents($src);
            file_put_contents($dest, $tamper ? $tamper(basename($url), $bytes) : $bytes);

            return 200;
        };

        return new ReleaseClient('https://api.github.com', 'elcreator/aEvoAST', null, $get, $download);
    }

    private function current(): array
    {
        return IndexMeta::describe(new FakeEmbedder());
    }

    public function testBuilderWritesIndexesAndAManifestItWouldAccept(): void
    {
        $manifest = $this->build();

        $this->assertSame(['evolution-cms', 'evo-docs'], array_column($manifest['sources'], 'name'));
        $this->assertFileExists($this->dist . '/evolution-cms_3.5.9.embeddings.bin');
        $this->assertFileExists($this->dist . '/evolution-cms_3.5.9.symbols.json');
        $this->assertFileExists($this->dist . '/evo-docs.embeddings.bin', 'documentation is not named after a version');

        $valid = Manifest::validate(json_decode(file_get_contents($this->dist . '/manifest.json'), true));
        $this->assertSame('83d4310', $valid['sources'][1]['revision']);
        $this->assertSame(hash_file('sha256', $this->dist . '/evo-docs.embeddings.bin'), $valid['sources'][1]['file']['sha256']);

        $h = BinaryIndex::header($this->dist . '/evolution-cms_3.5.9.embeddings.bin');
        $this->assertSame('bge-m3', $h['model']);
        $this->assertSame('core', $h['layer']);
    }

    public function testBuilderRefusesToPublishHalfAnIndex(): void
    {
        $broken = new class extends FakeEmbedder {
            public function embedBatch(array $texts): array { throw new \RuntimeException('Ollama fell over'); }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ollama fell over');
        $this->build($broken);
    }

    public function testBuilderRefusesAnEmptySource(): void
    {
        mkdir($this->root . '/empty');

        $this->expectException(\RuntimeException::class);
        (new ReleaseBuilder())->build([['path' => $this->root . '/empty', 'layer' => 'core', 'name' => 'x']], new FakeEmbedder(), $this->dist, '1');
    }

    public function testFullRoundTripInstallsWhatWasBuilt(): void
    {
        $this->build();
        $client = $this->client();
        $release = $client->releaseByTag('index-3.5.9');
        $plan = Manifest::select($client->manifest($release), $this->current(), ['en']);

        $this->assertCount(2, $plan['install']);
        $results = (new Puller($client, $this->cache))->install($release, $plan['install'], 'index-3.5.9');

        $this->assertSame(['installed', 'installed'], array_column($results, 'status'));
        $chunks = iterator_to_array(BinaryIndex::iterate($this->cache . '/evolution-cms_3.5.9.embeddings.bin'), false);
        $this->assertContains('evolution-cms::Evo\Core::clearCache', array_column($chunks, 'id'));
        $this->assertNotEmpty($chunks[0]['embedding']);
        $this->assertFileDoesNotExist($this->cache . '/evolution-cms_3.5.9.embeddings.bin.part');

        $registry = json_decode(file_get_contents($this->cache . '/pulled.json'), true);
        $this->assertSame('index-3.5.9', $registry['evolution-cms_3.5.9.embeddings.bin']['tag']);
    }

    public function testASecondPullDownloadsNothing(): void
    {
        $this->build();
        $calls = [];
        $client = $this->client($calls);
        $release = $client->releaseByTag('index-3.5.9');
        $plan = Manifest::select($client->manifest($release), $this->current(), ['en']);
        $puller = new Puller($client, $this->cache);

        $puller->install($release, $plan['install'], 'index-3.5.9');
        $downloads = count(array_filter($calls, fn($u) => str_contains($u, '.bin') || str_contains($u, 'symbols')));
        $again = $puller->install($release, $plan['install'], 'index-3.5.9');

        $this->assertSame(['up-to-date', 'up-to-date'], array_column($again, 'status'));
        $this->assertSame($downloads, count(array_filter($calls, fn($u) => str_contains($u, '.bin') || str_contains($u, 'symbols'))));
    }

    public function testACorruptedDownloadIsNeverInstalled(): void
    {
        $this->build();
        $calls = [];
        $client = $this->client($calls, fn(string $name, string $bytes) => str_ends_with($name, '.bin') ? substr_replace($bytes, 'X', 40, 1) : $bytes);
        $release = $client->releaseByTag('index-3.5.9');
        $plan = Manifest::select($client->manifest($release), $this->current(), ['en']);

        $results = (new Puller($client, $this->cache))->install($release, $plan['install'], 'index-3.5.9');

        $this->assertSame('failed', $results[0]['status']);
        $this->assertStringContainsString('checksum', $results[0]['detail']);
        $this->assertFileDoesNotExist($this->cache . '/evolution-cms_3.5.9.embeddings.bin');
        $this->assertSame([], glob($this->cache . '/*.part'));
    }

    public function testAFileLargerThanTheManifestPromisesIsRefused(): void
    {
        $this->build();
        $calls = [];
        $client = $this->client($calls, fn(string $name, string $bytes) => str_ends_with($name, '.bin') ? $bytes . 'extra' : $bytes);
        $release = $client->releaseByTag('index-3.5.9');
        $plan = Manifest::select($client->manifest($release), $this->current(), ['en']);

        $results = (new Puller($client, $this->cache))->install($release, $plan['install'], 'index-3.5.9');

        $this->assertSame('failed', $results[0]['status']);
        $this->assertStringContainsString('size', $results[0]['detail']);
    }

    public function testAManifestThatLiesAboutTheModelIsCaughtByTheFile(): void
    {
        $this->build();
        $manifest = json_decode(file_get_contents($this->dist . '/manifest.json'), true);
        $manifest['sources'][0]['model'] = 'some-other-model';
        file_put_contents($this->dist . '/manifest.json', json_encode($manifest));

        $client = $this->client();
        $release = $client->releaseByTag('index-3.5.9');
        $plan = Manifest::select($client->manifest($release), IndexMeta::describe(new FakeEmbedder('some-other-model')), ['en']);

        $results = (new Puller($client, $this->cache))->install($release, $plan['install'], 'index-3.5.9');

        $this->assertSame('failed', $results[0]['status']);
        $this->assertStringContainsString('does not match', $results[0]['detail']);
    }

    public function testDocumentationLanguagesAreChosenAndOthersLeftAlone(): void
    {
        $this->build();
        $manifest = Manifest::validate(json_decode(file_get_contents($this->dist . '/manifest.json'), true));

        $this->assertSame(['evolution-cms'], array_column(Manifest::select($manifest, $this->current(), [])['install'], 'name'));
        $this->assertSame(['evolution-cms', 'evo-docs'], array_column(Manifest::select($manifest, $this->current(), ['en'])['install'], 'name'));
        $this->assertSame(['evolution-cms'], array_column(Manifest::select($manifest, $this->current(), ['ru'])['install'], 'name'));
        $this->assertSame(['evo-docs'], array_column(Manifest::select($manifest, $this->current(), ['en'], 'evo-docs')['install'], 'name'));
    }

    public function testAnIndexMadeWithAnotherModelIsNotTakenAndSaysWhy(): void
    {
        $this->build();
        $manifest = Manifest::validate(json_decode(file_get_contents($this->dist . '/manifest.json'), true));

        $plan = Manifest::select($manifest, IndexMeta::describe(new FakeEmbedder('nomic-embed-text', 768)), ['en']);

        $this->assertSame([], $plan['install']);
        $this->assertStringContainsString('bge-m3', $plan['skipped'][0]['reason']);
        $this->assertStringContainsString('nomic-embed-text', $plan['skipped'][0]['reason']);
    }

    public function testChunkVersionsFromTheFutureAndThePastAreNotTaken(): void
    {
        $this->build();
        $raw = json_decode(file_get_contents($this->dist . '/manifest.json'), true);
        $raw['sources'][0]['chunks'] = IndexMeta::CHUNK_VERSION + 1;
        $raw['sources'][1]['chunks'] = IndexMeta::CHUNK_VERSION - 1;

        $plan = Manifest::select(Manifest::validate($raw), $this->current(), ['en']);

        $this->assertSame([], $plan['install']);
        $this->assertStringContainsString('update the package', $plan['skipped'][0]['reason']);
        $this->assertStringContainsString('older', $plan['skipped'][1]['reason']);
    }

    public function testNoReleaseForAVersionListsTheNewestPublishedOnes(): void
    {
        $this->build();
        $client = $this->client();

        $this->assertNull($client->releaseByTag('index-9.9.9'));
        $this->assertSame(['index-3.5.10', 'index-3.5.9', 'index-3.5.8'], $client->indexTags(), 'newest version first; other tags and prereleases left out');
    }

    #[DataProvider('unsafeManifests')]
    public function testAManifestCannotPlaceFilesWhereItLikes(callable $mutate): void
    {
        $this->build();
        $raw = json_decode(file_get_contents($this->dist . '/manifest.json'), true);
        $mutate($raw);

        $this->expectException(\RuntimeException::class);
        Manifest::validate($raw);
    }

    public static function unsafeManifests(): array
    {
        return [
            'path traversal in a file name' => [function (&$m) { $m['sources'][0]['file']['name'] = '../../evil.embeddings.bin'; }],
            'file of another source'        => [function (&$m) { $m['sources'][0]['file']['name'] = 'evo-docs.embeddings.bin'; }],
            'name with dots'                => [function (&$m) { $m['sources'][0]['name'] = '../x'; }],
            'unknown layer'                 => [function (&$m) { $m['sources'][0]['layer'] = 'local'; }],
            'checksum not hex'              => [function (&$m) { $m['sources'][0]['file']['sha256'] = 'zz'; }],
            'absurd size'                   => [function (&$m) { $m['sources'][0]['file']['bytes'] = PHP_INT_MAX; }],
            'size zero'                     => [function (&$m) { $m['sources'][0]['file']['bytes'] = 0; }],
            'unknown manifest format'       => [function (&$m) { $m['format'] = 99; }],
            'missing symbols'               => [function (&$m) { unset($m['sources'][0]['symbols']); }],
            'dimensions as a string'        => [function (&$m) { $m['sources'][0]['dimensions'] = '4'; }],
        ];
    }

    public function testDownloadsOnlyFromGithubOrTheConfiguredHost(): void
    {
        $this->build();
        $get = fn() => [200, json_encode(['assets' => [['name' => 'a', 'browser_download_url' => 'https://evil.example/a']]])];

        $client = new ReleaseClient('https://api.github.com', 'elcreator/aEvoAST', null, $get, fn() => 200);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected address');
        $client->download($client->releaseByTag('index-1'), 'a', $this->root . '/a', 10);
    }

    public function testPlainHttpIsRefusedExceptForLoopback(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReleaseClient('http://api.example.com', 'o/r');
    }

    public function testLoopbackMirrorIsAllowedForTestingAndLocalMirrors(): void
    {
        $client = new ReleaseClient('http://127.0.0.1:8099', 'o/r', null, fn() => [404, ''], fn() => 404);
        $this->assertNull($client->releaseByTag('index-1'));
    }

    public function testBadRepositoryNamesAndTagsAreRefused(): void
    {
        foreach (['', 'nope', '../x/y', 'a/b/c', 'a b/c'] as $repo) {
            try {
                new ReleaseClient('https://api.github.com', $repo);
                $this->fail("accepted repo \"{$repo}\"");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $client = new ReleaseClient('https://api.github.com', 'o/r', null, fn() => [404, ''], fn() => 404);
        $this->expectException(\InvalidArgumentException::class);
        $client->releaseByTag('../../etc/passwd');
    }

    public function testRateLimitSaysHowToFixIt(): void
    {
        $client = new ReleaseClient('https://api.github.com', 'o/r', null, fn() => [403, '{}'], fn() => 404);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AST_PULL_TOKEN');
        $client->indexTags();
    }

    public function testTheTokenGoesToTheApiAndNeverWithADownload(): void
    {
        $seen = [];
        $get = function (string $url, array $headers) use (&$seen) {
            $seen[$url] = $headers;
            return str_ends_with($url, 'manifest.json') ? [200, '{}'] : [200, json_encode(['assets' => [['name' => 'manifest.json', 'browser_download_url' => 'https://github.com/o/r/releases/download/t/manifest.json']]])];
        };
        $client = new ReleaseClient('https://api.github.com', 'o/r', 's3cret', $get, fn() => 200);

        $release = $client->releaseByTag('index-1');
        try {
            $client->manifest($release);
        } catch (\RuntimeException) {
            // an empty manifest is invalid; only the headers matter here
        }

        $apiHeaders = implode('|', $seen['https://api.github.com/repos/o/r/releases/tags/index-1']);
        $fileHeaders = implode('|', $seen['https://github.com/o/r/releases/download/t/manifest.json']);
        $this->assertStringContainsString('Bearer s3cret', $apiHeaders);
        $this->assertStringNotContainsString('s3cret', $fileHeaders);
    }
}
