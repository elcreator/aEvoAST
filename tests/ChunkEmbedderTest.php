<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Embeddings\ChunkEmbedder;
use Elcreator\aEvoAST\Parsers\SourceIndexer;
use PHPUnit\Framework\TestCase;

class ChunkEmbedderTest extends TestCase
{
    private function chunks(int $n): array
    {
        return array_map(fn($i) => ['id' => "c$i", 'text' => "text $i", 'meta' => []], range(0, $n - 1));
    }

    private function embedder(?callable $onBatch = null): FakeEmbedder
    {
        return new class($onBatch) extends FakeEmbedder {
            public array $batches = [];
            public function __construct(private $hook) { parent::__construct(); }
            public function embedBatch(array $texts): array
            {
                $this->batches[] = count($texts);
                if ($this->hook) {
                    ($this->hook)(count($this->batches));
                }

                return parent::embedBatch($texts);
            }
        };
    }

    public function testEveryChunkGetsItsOwnVectorInOrderAcrossBatches(): void
    {
        $e = $this->embedder();
        $out = (new ChunkEmbedder())->embed($this->chunks(7), $e, 3);

        $this->assertSame([3, 3, 1], $e->batches);
        foreach ($out as $i => $c) {
            $this->assertSame((new FakeEmbedder())->embed("text $i"), $c['embedding']);
        }
    }

    public function testProgressIsReportedPerBatch(): void
    {
        $seen = [];
        (new ChunkEmbedder())->embed($this->chunks(5), $this->embedder(), 2, function ($done, $total, $err) use (&$seen) {
            $seen[] = [$done, $total, $err];
        });

        $this->assertSame([[2, 5, null], [4, 5, null], [5, 5, null]], $seen);
    }

    public function testAFailedBatchLeavesThoseChunksWithoutAVectorAndTheRestIntact(): void
    {
        $e = $this->embedder(function (int $n) {
            if ($n === 2) {
                throw new \RuntimeException('timeout');
            }
        });
        $errors = [];
        $out = (new ChunkEmbedder())->embed($this->chunks(6), $e, 2, function ($d, $t, $err) use (&$errors) {
            $errors[] = $err;
        });

        $this->assertSame([null, 'timeout', null], $errors);
        $this->assertNotEmpty($out[0]['embedding']);
        $this->assertSame([], $out[2]['embedding']);
        $this->assertSame([], $out[3]['embedding']);
        $this->assertNotEmpty($out[4]['embedding']);
    }

    public function testStrictModeStopsAtTheFirstFailure(): void
    {
        $e = $this->embedder(function (int $n) {
            if ($n === 2) {
                throw new \RuntimeException('timeout');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Embedding failed at chunk 2: timeout');
        (new ChunkEmbedder())->embed($this->chunks(6), $e, 2, null, strict: true);
    }

    public function testAnswerWithTheWrongNumberOfVectorsIsAFailureNotAMisalignment(): void
    {
        $short = new class extends FakeEmbedder {
            public function embedBatch(array $texts): array { return array_slice(parent::embedBatch($texts), 1); }
        };

        $this->expectException(\RuntimeException::class);
        (new ChunkEmbedder())->embed($this->chunks(3), $short, 3, null, strict: true);
    }

    public function testVeryLongTextIsCutBeforeItIsSent(): void
    {
        $sent = [];
        $spy = new class($sent) extends FakeEmbedder {
            public function __construct(public array &$sent) { parent::__construct(); }
            public function embedBatch(array $texts): array { $this->sent = array_merge($this->sent, $texts); return parent::embedBatch($texts); }
        };
        (new ChunkEmbedder())->embed([['id' => 'a', 'text' => str_repeat('é', 9000), 'meta' => []]], $spy);

        $this->assertSame(4000, mb_strlen($sent[0]));
    }

    public function testIndexerTurnsADocsTreeIntoSymbolsAndChunks(): void
    {
        $dir = sys_get_temp_dir() . '/aast-idx-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/A.md', "# Cache\n\nCall clearCache() to empty the whole site cache at once.\n");

        $r = (new SourceIndexer())->index(['path' => $dir, 'layer' => 'docs', 'name' => 'evo-docs', 'lang' => 'en']);
        unlink($dir . '/A.md');
        rmdir($dir);

        $this->assertCount(1, $r['chunks']);
        $this->assertSame('doc', $r['symbols'][0]['kind']);
        $this->assertSame('evo-docs', $r['symbols'][0]['source']);
        $this->assertSame([], $r['errors']);
    }
}
