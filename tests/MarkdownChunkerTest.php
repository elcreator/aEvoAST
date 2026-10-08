<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Parsers\Chunker;
use Elcreator\aEvoAST\Parsers\MarkdownChunker;
use Elcreator\aEvoAST\Parsers\SymbolMapParser;
use PHPUnit\Framework\TestCase;

class MarkdownChunkerTest extends TestCase
{
    public function testSectionsCarryTheirPathAndHeadings(): void
    {
        $md = "# Cache\n\nIntro paragraph that is long enough to keep.\n\n## Clearing\n\nCall \$modx->clearCache() to empty the site cache.\n";
        $chunks = (new MarkdownChunker())->chunkFile($md, '03_Developers/10_API/Clear_cache.md', 'evo-docs');

        $this->assertCount(2, $chunks);
        $this->assertStringStartsWith("Developers > API > Clear cache > Cache > Clearing\n", $chunks[1]['text']);
        $this->assertStringContainsString('clearCache()', $chunks[1]['text']);
        $this->assertSame('doc', $chunks[1]['meta']['kind']);
        $this->assertSame('docs', $chunks[1]['meta']['layer']);
        $this->assertSame('Clearing', $chunks[1]['meta']['name']);
        $this->assertSame('evo-docs::03_Developers/10_API/Clear_cache.md#2', $chunks[1]['id']);
    }

    public function testHashLinesInsideCodeFencesAreNotHeadings(): void
    {
        $md = "## Example\n\nSome words before the code.\n\n```php\n# a comment, not a heading\n\$x = 1;\n```\n";
        $chunks = (new MarkdownChunker())->chunkFile($md, 'a.md', 's');

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('# a comment, not a heading', $chunks[0]['text']);
    }

    public function testFrontMatterAndTinySectionsAreDropped(): void
    {
        $md = "---\ntitle: x\n---\n# A\n\nok\n\n# B\n\nThis section has enough text to be worth a chunk.\n";
        $chunks = (new MarkdownChunker())->chunkFile($md, 'a.md', 's');

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('worth a chunk', $chunks[0]['text']);
        $this->assertStringNotContainsString('title: x', $chunks[0]['text']);
    }

    public function testLongSectionsAreSplitAndNoPieceRunsAway(): void
    {
        $para = str_repeat('word ', 80); // 400 chars
        $md = "# Big\n\n" . implode("\n\n", array_fill(0, 12, $para)) . "\n";
        $chunks = (new MarkdownChunker(maxChars: 1000))->chunkFile($md, 'a.md', 's');

        $this->assertGreaterThan(2, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThan(2000, strlen($c['text']));
        }
    }

    public function testSummaryOfADocblockEndsUpInTheChunkText(): void
    {
        $dir = sys_get_temp_dir() . '/aast-sum-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/Core.php', <<<'PHP'
<?php
namespace App;

/**
 * The application core. More detail
 * that is not the summary.
 */
class Core
{
    /**
     * Clear the cache of MODX.
     *
     * @param string $type 'full'
     * @return void
     */
    public function clearCache($type = ''): void {}

    /** Returns 1 if user has the permission */
    public function hasPermission($pm) {}

    public function bare() {}
}
PHP);

        $symbols = (new SymbolMapParser())->parseDirectory($dir, 'core', 'x')['symbols'];
        $texts = array_column((new Chunker())->chunk($symbols, 'method'), 'text');
        unlink($dir . '/Core.php');
        rmdir($dir);

        $this->assertContains('class App\Core - The application core.', $texts);
        $this->assertContains('App\Core::clearCache($type = …): void [public] - Clear the cache of MODX.', $texts);
        $this->assertContains('App\Core::hasPermission($pm) [public] - Returns 1 if user has the permission', $texts);
        $this->assertContains('App\Core::bare() [public]', $texts);
    }
}
