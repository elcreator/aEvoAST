<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Storage\FileWriter;
use PHPUnit\Framework\TestCase;

class FileWriterTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/aast-fw-' . bin2hex(random_bytes(4)) . '.embeddings.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function chunks(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'id' => "c$i",
                'text' => "Cls::m$i(string \$a): void [public] \"quoted\" / slash é",
                'meta' => ['layer' => 'core', 'overridden' => false, 'line_start' => $i],
                'embedding' => [0.1 * $i, -0.25, 1.0e-7],
            ];
        }

        return $out;
    }

    public function testSymbolsWithInvalidUtf8AreWrittenNotLeftEmpty(): void
    {
        (new FileWriter())->writeSymbols($this->file, [['name' => "Bad\xB1Name", 'doc' => 'ok']], 'json');

        $this->assertGreaterThan(0, filesize($this->file));
        $this->assertSame('ok', json_decode(file_get_contents($this->file), true)[0]['doc']);
    }

    public function testRoundTrip(): void
    {
        $w = new FileWriter();
        $w->writeEmbeddings($this->file, $this->chunks(5));

        $this->assertEquals($this->chunks(5), $w->readEmbeddings($this->file));
    }

    public function testFileIsStillOneValidJsonDocumentOnePerLine(): void
    {
        (new FileWriter())->writeEmbeddings($this->file, $this->chunks(3));

        $this->assertCount(3, json_decode(file_get_contents($this->file), true));
        $this->assertCount(5, file($this->file), 'brackets plus one line per chunk');
    }

    public function testEmptyAndSingleChunk(): void
    {
        $w = new FileWriter();
        $w->writeEmbeddings($this->file, []);
        $this->assertSame([], $w->readEmbeddings($this->file));

        $w->writeEmbeddings($this->file, $this->chunks(1));
        $this->assertCount(1, $w->readEmbeddings($this->file));
    }

    public function testOlderPrettyPrintedIndexesStillLoad(): void
    {
        file_put_contents($this->file, json_encode($this->chunks(4), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->assertEquals($this->chunks(4), (new FileWriter())->readEmbeddings($this->file));
    }

    public function testMissingFileIsEmpty(): void
    {
        $this->assertSame([], (new FileWriter())->readEmbeddings($this->file . '.nope'));
    }
}
