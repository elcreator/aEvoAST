<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Parsers\SymbolMapParser;
use PHPUnit\Framework\TestCase;

class SymbolMapParserTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aast-parse-' . bin2hex(random_bytes(4));
        foreach (['src', 'vendor/acme/lib', 'node_modules/x'] as $d) {
            mkdir($this->dir . '/' . $d, 0777, true);
        }
        file_put_contents($this->dir . '/src/Mine.php', '<?php namespace App; class Mine { public function run(): void {} }');
        file_put_contents($this->dir . '/vendor/acme/lib/Theirs.php', '<?php namespace Acme; class Theirs { public function go(): void {} }');
        file_put_contents($this->dir . '/node_modules/x/Junk.php', '<?php class Junk {}');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function testNestedVendorAndNodeModulesAreNotPartOfTheSource(): void
    {
        $res = (new SymbolMapParser())->parseDirectory($this->dir, 'local', 'mine');
        $names = array_map(fn($s) => $s['name'], $res['symbols']);

        $this->assertSame(['Mine'], $names);
        $this->assertSame([], $res['errors']);
    }

    public function testScanningAVendorPackageDirectlyStillWorks(): void
    {
        // Only nested vendor/ is skipped, not a root that happens to live inside one.
        $res = (new SymbolMapParser())->parseDirectory($this->dir . '/vendor/acme/lib', 'extra', 'acme/lib');

        $this->assertSame(['Theirs'], array_map(fn($s) => $s['name'], $res['symbols']));
    }
}
