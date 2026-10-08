<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Storage\FileWriter;
use Elcreator\aEvoAST\Storage\Merger;
use PHPUnit\Framework\TestCase;

class MergerTest extends TestCase
{
    /** @var string[] */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
    }

    private function file(array $chunks): string
    {
        $f = sys_get_temp_dir() . '/aast-merge-' . bin2hex(random_bytes(4)) . '.embeddings.json';
        (new FileWriter())->writeEmbeddings($f, $chunks);

        return $this->files[] = $f;
    }

    private function method(string $layer, string $source, string $class, string $method): array
    {
        return [
            'id' => "$source::$class::$method",
            'text' => "$class::$method()",
            'meta' => ['kind' => 'method', 'class' => $class, 'method' => $method, 'layer' => $layer, 'source' => $source],
            'embedding' => [1.0, 0.0],
        ];
    }

    private function sets(): array
    {
        return [
            $this->file([
                $this->method('core', 'evolution-cms', 'Core', 'clearCache'),
                $this->method('core', 'evolution-cms', 'Core', 'runSnippet'),
            ]),
            $this->file([
                $this->method('local', 'local/custom', 'Core', 'clearCache'), // overrides core's
                $this->method('extra', 'seiger/slang', 'Slang', 'switch'),
            ]),
        ];
    }

    public function testHigherLayerWinsAndTheLoserIsKeptFlagged(): void
    {
        $out = iterator_to_array((new Merger())->mergeFiles($this->sets(), new FileWriter(), false, $overridden), false);

        $this->assertCount(4, $out);
        $this->assertSame(1, $overridden);

        $byLayer = [];
        foreach ($out as $c) {
            if ($c['meta']['method'] === 'clearCache') {
                $byLayer[$c['meta']['layer']] = $c['meta'];
            }
        }
        $this->assertTrue($byLayer['core']['overridden']);
        $this->assertSame('local/custom', $byLayer['core']['overridden_by']);
        $this->assertFalse($byLayer['local']['overridden']);
        $this->assertNull($byLayer['local']['overridden_by']);
    }

    public function testActiveOnlyDropsTheOverridden(): void
    {
        $out = iterator_to_array((new Merger())->mergeFiles($this->sets(), new FileWriter(), true, $overridden), false);

        $this->assertCount(3, $out);
        $this->assertSame(1, $overridden, 'still counted');
        foreach ($out as $c) {
            $this->assertFalse($c['meta']['overridden']);
        }
    }

    public function testSameResultAsTheInMemoryMerge(): void
    {
        $writer = new FileWriter();
        $files = $this->sets();
        $merger = new Merger();

        $inMemory = $merger->merge(array_map(fn($f) => $writer->readEmbeddings($f), $files));
        $streamed = iterator_to_array($merger->mergeFiles($files, $writer), false);

        $key = fn($c) => $c['id'] . '|' . $c['meta']['layer'] . '|' . var_export($c['meta']['overridden'], true) . '|' . ($c['meta']['overridden_by'] ?? '');
        $a = array_map($key, $inMemory);
        $b = array_map($key, $streamed);
        sort($a);
        sort($b);
        $this->assertSame($a, $b);
    }

    public function testStreamedWriteIsReadableAndHandlesNothing(): void
    {
        $writer = new FileWriter();
        $path = sys_get_temp_dir() . '/aast-stream-' . bin2hex(random_bytes(4)) . '.embeddings.json';
        $this->files[] = $path;

        $writer->writeEmbeddingsStream($path, (function () {
            yield $this->method('core', 's', 'A', 'a');
            yield $this->method('core', 's', 'A', 'b');
        })());
        $this->assertCount(2, json_decode(file_get_contents($path), true));
        $this->assertCount(2, $writer->readEmbeddings($path));

        $writer->writeEmbeddingsStream($path, []);
        $this->assertSame([], json_decode(file_get_contents($path), true));
        $this->assertSame([], $writer->readEmbeddings($path));
    }

    public function testDocumentationSectionsWithTheSameHeadingDoNotOverrideEachOther(): void
    {
        $doc = fn(string $file) => [
            'id' => "evo-docs::$file#1",
            'text' => 'Example',
            'meta' => ['kind' => 'doc', 'name' => 'Example', 'layer' => 'docs', 'source' => 'evo-docs', 'file' => $file],
            'embedding' => [],
        ];
        $files = [$this->file([$doc('a.md'), $doc('b.md')]), $this->file([$doc('c.md')])];

        $out = iterator_to_array((new Merger())->mergeFiles($files, new FileWriter(), false, $overridden), false);

        $this->assertCount(3, $out);
        $this->assertSame(0, $overridden);
    }
}
