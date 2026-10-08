<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Embeddings\EmbedderFactory;
use Elcreator\aEvoAST\Embeddings\OllamaEmbedder;
use Elcreator\aEvoAST\Embeddings\OpenAiEmbedder;
use Elcreator\aEvoAST\Storage\IndexMeta;
use PHPUnit\Framework\TestCase;

class IndexMetaTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aast-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testMissingIndexReadsAsNull(): void
    {
        $this->assertNull(IndexMeta::read($this->dir . '/none.json'));
    }

    public function testIndexWithoutSidecarIsTakenForNomic(): void
    {
        file_put_contents($this->dir . '/a.json', '[]');
        $meta = IndexMeta::read($this->dir . '/a.json');

        $this->assertSame('nomic-embed-text', $meta['model']);
        $this->assertSame(768, $meta['dimensions']);
        $this->assertTrue($meta['legacy']);
    }

    public function testSidecarRoundTrip(): void
    {
        file_put_contents($this->dir . '/a.json', '[]');
        $e = new OpenAiEmbedder('https://x/v1', 'text-embedding-3-small', 'k');
        IndexMeta::write($this->dir . '/a.json', IndexMeta::describe($e));

        $meta = IndexMeta::read($this->dir . '/a.json');
        $this->assertSame('openai', $meta['driver']);
        $this->assertSame('text-embedding-3-small', $meta['model']);
        $this->assertSame(1536, $meta['dimensions']);
        $this->assertArrayNotHasKey('legacy', $meta);
    }

    public function testCorruptSidecarFallsBackToLegacy(): void
    {
        file_put_contents($this->dir . '/a.json', '[]');
        file_put_contents(IndexMeta::path($this->dir . '/a.json'), '{not json');

        $this->assertTrue(IndexMeta::read($this->dir . '/a.json')['legacy']);
    }

    public function testSameSpaceRules(): void
    {
        $nomic = ['model' => 'nomic-embed-text', 'dimensions' => 768];

        $this->assertTrue(IndexMeta::sameSpace($nomic, ['model' => 'nomic-embed-text:latest', 'dimensions' => 768]));
        $this->assertTrue(IndexMeta::sameSpace($nomic, ['model' => 'nomic-embed-text', 'dimensions' => 0]), 'unknown size matches');
        $this->assertFalse(IndexMeta::sameSpace($nomic, ['model' => 'text-embedding-3-small', 'dimensions' => 1536]));
        $this->assertFalse(IndexMeta::sameSpace(
            ['model' => 'text-embedding-3-small', 'dimensions' => 1536],
            ['model' => 'text-embedding-3-small', 'dimensions' => 256],
        ));
    }

    public function testOllamaDefaultsToNomicSize(): void
    {
        $e = new OllamaEmbedder('http://localhost:11434', 'nomic-embed-text');
        $this->assertSame(768, $e->getDimensions());
        $this->assertSame(0, (new OllamaEmbedder('http://localhost:11434', 'mxbai-embed-large'))->getDimensions());
        $this->assertSame(1024, (new OllamaEmbedder('http://localhost:11434', 'bge-m3'))->getDimensions());
        $this->assertSame(1024, (new OllamaEmbedder('http://localhost:11434', 'bge-m3:latest'))->getDimensions());
    }

    public function testOllamaIsLocalUnlessPointedElsewhere(): void
    {
        $this->assertFalse((new OllamaEmbedder('http://localhost:11434', 'm'))->isRemote());
        $this->assertFalse((new OllamaEmbedder('http://192.168.88.41:11500', 'm'))->isRemote());
        $this->assertTrue((new OllamaEmbedder('https://ollama.example.com', 'm'))->isRemote());
    }

    public function testFactoryPicksTheDriver(): void
    {
        $this->assertInstanceOf(OllamaEmbedder::class, EmbedderFactory::make([]));
        $this->assertSame('bge-m3', EmbedderFactory::make([])->getModel(), 'the default model');
        $this->assertInstanceOf(OllamaEmbedder::class, EmbedderFactory::make(['embedder' => 'ollama']));

        $e = EmbedderFactory::make(['embedder' => 'OpenAI', 'openai' => ['url' => 'https://gw/v1', 'model' => 'text-embedding-3-large', 'key' => 'k', 'dimensions' => '512']]);
        $this->assertInstanceOf(OpenAiEmbedder::class, $e);
        $this->assertSame(512, $e->getDimensions());
    }

    public function testFactoryRejectsAnUnknownDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EmbedderFactory::make(['embedder' => 'cohere']);
    }

    public function testRemoteEmbedderDoesNotSeeLocalCodeUnlessAllowed(): void
    {
        $remote = new OpenAiEmbedder('https://gw/v1', 'm', 'k');
        $local = new OllamaEmbedder('http://localhost:11434', 'nomic-embed-text');

        $this->assertFalse(EmbedderFactory::mayEmbedLocal($remote, []));
        $this->assertTrue(EmbedderFactory::mayEmbedLocal($remote, ['allow_remote_local' => true]));
        $this->assertTrue(EmbedderFactory::mayEmbedLocal($local, []));
    }

    public function testKeywordOnlyIndexFitsAnyModelAndSaysSo(): void
    {
        $none = IndexMeta::lexicalOnly();
        $nomic = ['model' => 'nomic-embed-text', 'dimensions' => 768];

        $this->assertTrue(IndexMeta::isLexicalOnly($none));
        $this->assertFalse(IndexMeta::isLexicalOnly($nomic));
        $this->assertTrue(IndexMeta::sameSpace($none, $nomic));
        $this->assertTrue(IndexMeta::sameSpace($nomic, $none));
        $this->assertStringContainsString('keyword only', IndexMeta::label($none));
    }

    public function testKeywordOnlySidecarIsNotMistakenForLegacy(): void
    {
        file_put_contents($this->dir . '/k.json', '[]');
        IndexMeta::write($this->dir . '/k.json', IndexMeta::lexicalOnly());

        $meta = IndexMeta::read($this->dir . '/k.json');
        $this->assertTrue(IndexMeta::isLexicalOnly($meta));
        $this->assertArrayNotHasKey('legacy', $meta);
    }

    public function testCachesFromAnOlderChunkerAreNotCurrent(): void
    {
        $this->assertFalse(IndexMeta::isCurrent(IndexMeta::LEGACY));
        $this->assertFalse(IndexMeta::isCurrent(['model' => 'nomic-embed-text', 'dimensions' => 768]));
        $this->assertTrue(IndexMeta::isCurrent(IndexMeta::lexicalOnly()));
        $this->assertTrue(IndexMeta::isCurrent(IndexMeta::describe(new OllamaEmbedder('http://localhost:11434', 'nomic-embed-text'))));
    }
}
