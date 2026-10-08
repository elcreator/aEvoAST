<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Storage\BinaryIndex;
use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\IndexMeta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BinaryIndexTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aast-bin-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    private function chunk(int $i, ?array $vector): array
    {
        return [
            'id'   => "src::Class::m$i",
            'text' => "Class::m$i(string \$a): void - ünïcode \"quoted\" / slash\nsecond line",
            'meta' => ['kind' => 'method', 'layer' => 'core', 'line_start' => $i],
            'embedding' => $vector ?? [],
        ];
    }

    private function meta(int $dims = 3): array
    {
        return ['driver' => 'ollama', 'model' => 'bge-m3', 'dimensions' => $dims, 'chunks' => 3, 'created_at' => '2026-10-08T00:00:00+00:00'];
    }

    public function testRoundTripKeepsTextMetaAndVectors(): void
    {
        $path = $this->dir . '/a.bin';
        $chunks = [$this->chunk(0, [0.5, -0.25, 1.0]), $this->chunk(1, [0.0, 0.125, -2.0])];

        BinaryIndex::write($path, $chunks, $this->meta());
        $back = iterator_to_array(BinaryIndex::iterate($path), false);

        $this->assertCount(2, $back);
        $this->assertSame($chunks[1]['text'], $back[1]['text']);
        $this->assertSame($chunks[0]['meta'], $back[0]['meta']);
        $this->assertSame([0.5, -0.25, 1.0], $back[0]['embedding'], 'powers of two survive float32 exactly');
        $this->assertSame([0.0, 0.125, -2.0], $back[1]['embedding']);
    }

    public function testHeaderIsReadableWithoutTheChunks(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, [1.0, 2.0, 3.0])], $this->meta() + ['source' => 'evolution-cms', 'version' => '3.5.9']);

        $h = BinaryIndex::header($path);
        $this->assertSame(1, $h['count']);
        $this->assertSame(3, $h['dimensions']);
        $this->assertTrue($h['hasVectors']);
        $this->assertSame('bge-m3', $h['model']);
        $this->assertSame('3.5.9', $h['version']);
    }

    public function testNumbersAreStoredAsFloat32(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, [0.1, 0.2, 0.3])], $this->meta());
        $v = iterator_to_array(BinaryIndex::iterate($path))[0]['embedding'];

        $this->assertNotSame(0.1, $v[0]);
        $this->assertEqualsWithDelta(0.1, $v[0], 1e-7);
        $this->assertEqualsWithDelta(0.3, $v[2], 1e-7);
    }

    public function testAChunkWithoutAVectorIsKeptAndMarked(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, null), $this->chunk(1, [1.0, 1.0, 1.0]), $this->chunk(2, null)], $this->meta());

        $back = iterator_to_array(BinaryIndex::iterate($path), false);
        $this->assertSame([], $back[0]['embedding']);
        $this->assertSame([1.0, 1.0, 1.0], $back[1]['embedding']);
        $this->assertSame([], $back[2]['embedding']);
        $this->assertSame([0, 2], BinaryIndex::header($path)['missing']);
    }

    public function testSizeIsLearnedFromTheFirstVectorWhenTheMetaDoesNotKnowIt(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, null), $this->chunk(1, [1.0, 2.0])], $this->meta(0));

        $this->assertSame(2, BinaryIndex::header($path)['dimensions']);
        $this->assertSame([1.0, 2.0], iterator_to_array(BinaryIndex::iterate($path), false)[1]['embedding']);
    }

    public function testKeywordOnlyIndexHasNoVectorsAndSaysSo(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, [1.0, 2.0, 3.0]), $this->chunk(1, null)], IndexMeta::lexicalOnly());

        $h = BinaryIndex::header($path);
        $this->assertFalse($h['hasVectors']);
        $this->assertSame(0, $h['vectorBytes']);
        $this->assertTrue(IndexMeta::isLexicalOnly(IndexMeta::read($path)));
        $this->assertSame([], iterator_to_array(BinaryIndex::iterate($path), false)[0]['embedding']);
    }

    public function testAnIndexWhereNothingGotEmbeddedBecomesKeywordOnly(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, null)], $this->meta());

        $this->assertTrue(IndexMeta::isLexicalOnly(IndexMeta::read($path)));
    }

    public function testVectorsOfTheWrongSizeAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        BinaryIndex::write($this->dir . '/a.bin', [$this->chunk(0, [1.0, 2.0])], $this->meta());
    }

    public function testEmptyIndex(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [], $this->meta());

        $this->assertSame(0, BinaryIndex::header($path)['count']);
        $this->assertSame([], iterator_to_array(BinaryIndex::iterate($path), false));
    }

    public function testAFailedWriteLeavesNothingBehind(): void
    {
        try {
            BinaryIndex::write($this->dir . '/a.bin', [$this->chunk(0, [1.0])], $this->meta());
        } catch (\RuntimeException) {
        }

        $this->assertSame([], glob($this->dir . '/*'));
    }

    #[DataProvider('damaged')]
    public function testDamagedFilesAreRefusedNotTrusted(callable $damage): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, [1.0, 2.0, 3.0]), $this->chunk(1, [4.0, 5.0, 6.0])], $this->meta());
        file_put_contents($path, $damage(file_get_contents($path)));

        $this->expectException(\RuntimeException::class);
        iterator_to_array(BinaryIndex::iterate($path));
    }

    public static function damaged(): array
    {
        return [
            'truncated'        => [fn($b) => substr($b, 0, -5)],
            'padded'           => [fn($b) => $b . 'x'],
            'wrong magic'      => [fn($b) => 'NOPE' . substr($b, 4)],
            'empty'            => [fn($b) => ''],
            'huge header size' => [fn($b) => substr($b, 0, 8) . pack('V', 0x7fffffff) . substr($b, 12)],
            'zero header size' => [fn($b) => substr($b, 0, 8) . pack('V', 0) . substr($b, 12)],
            'garbage header'   => [fn($b) => substr($b, 0, 12) . str_repeat('x', unpack('V', substr($b, 8, 4))[1]) . substr($b, 12 + unpack('V', substr($b, 8, 4))[1])],
        ];
    }

    public function testChunksThatDoNotDecompressAreRefused(): void
    {
        $path = $this->dir . '/a.bin';
        BinaryIndex::write($path, [$this->chunk(0, [1.0, 2.0, 3.0])], $this->meta());
        $bytes = file_get_contents($path);
        $headerLen = unpack('V', substr($bytes, 8, 4))[1];
        $metaStart = 12 + $headerLen;
        $bytes[$metaStart + 12] = chr(ord($bytes[$metaStart + 12]) ^ 0xFF); // flip a byte inside the gzip stream
        file_put_contents($path, $bytes);

        $this->expectException(\RuntimeException::class);
        iterator_to_array(BinaryIndex::iterate($path));
    }

    public function testFileWriterSpeaksBinaryAndRequiresTheModel(): void
    {
        $w = new FileWriter();
        $path = $this->dir . '/b.embeddings.bin';
        $w->writeEmbeddings($path, [$this->chunk(0, [1.0, 2.0, 3.0])], 'bin', $this->meta());

        $this->assertCount(1, $w->readEmbeddings($path));
        $this->assertCount(1, iterator_to_array($w->iterateEmbeddings($path)));
        $this->assertSame('bge-m3', IndexMeta::read($path)['model']);

        $w->writeEmbeddingsStream($path, (fn() => yield $this->chunk(7, [1.0, 1.0, 1.0]))(), 'bin', $this->meta());
        $this->assertSame('src::Class::m7', $w->readEmbeddings($path)[0]['id']);

        $this->expectException(\InvalidArgumentException::class);
        $w->writeEmbeddings($path, [], 'bin');
    }

    public function testIndexMetaWriteDoesNotLeaveASidecarNextToABinaryIndex(): void
    {
        $path = $this->dir . '/c.embeddings.bin';
        BinaryIndex::write($path, [$this->chunk(0, [1.0, 2.0, 3.0])], $this->meta());
        IndexMeta::write($path, $this->meta());

        $this->assertFileDoesNotExist($path . '.meta.json');
    }

    public function testBinaryIsMuchSmallerThanJsonForRealisticVectors(): void
    {
        mt_srand(3);
        $chunks = [];
        for ($i = 0; $i < 200; $i++) {
            $v = [];
            for ($j = 0; $j < 256; $j++) {
                $v[] = mt_rand() / mt_getrandmax() - 0.5;
            }
            $chunks[] = $this->chunk($i, $v);
        }
        $w = new FileWriter();
        $w->writeEmbeddings($this->dir . '/x.embeddings.bin', $chunks, 'bin', $this->meta(256));
        $w->writeEmbeddings($this->dir . '/x.embeddings.json', $chunks, 'json');

        $this->assertLessThan(filesize($this->dir . '/x.embeddings.json') / 3, filesize($this->dir . '/x.embeddings.bin'));
    }
}
