<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Parsers\SourceDiscovery;
use PHPUnit\Framework\TestCase;

class SourceDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aast-disc-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->root);
    }

    private function dirs(string ...$dirs): void
    {
        foreach ($dirs as $d) {
            mkdir($this->root . '/' . $d, 0777, true);
        }
    }

    private function config(): array
    {
        return ['auto_extras' => true, 'extra_vendors' => ['seiger'], 'local_paths' => ['assets/snippets', 'core/custom']];
    }

    public function testEvolutionBasePathIsTheCoreDirectory(): void
    {
        // What base_path() is in Evolution CMS: <site>/core, with core/src/Core.php inside.
        $this->dirs('core/src', 'core/vendor/seiger/slang', 'assets/snippets', 'core/custom');
        file_put_contents($this->root . '/core/src/Core.php', '<?php class Core {}');
        file_put_contents($this->root . '/core/composer.lock', json_encode(['packages' => [
            ['name' => 'seiger/slang', 'version' => 'v3.0.1'],
        ]]));

        $found = (new SourceDiscovery($this->config()))->discover($this->root . '/core');
        $byName = array_column($found, null, 'name');

        $this->assertSame('core', $byName['evolution-cms']['layer']);
        $this->assertSame(realpath($this->root . '/core/src'), realpath($byName['evolution-cms']['path']));

        $this->assertSame('extra', $byName['seiger/slang']['layer']);
        $this->assertSame('v3.0.1', $byName['seiger/slang']['version']);

        // Local code is relative to the site, not to core/.
        $this->assertArrayHasKey('local/snippets', $byName);
        $this->assertArrayHasKey('local/custom', $byName);
        $this->assertSame(realpath($this->root . '/assets/snippets'), realpath($byName['local/snippets']['path']));
    }

    public function testProjectInstalledAsAPackage(): void
    {
        $this->dirs('vendor/evolution-cms/evolution/core', 'assets/snippets');

        $found = (new SourceDiscovery(['local_paths' => ['assets/snippets'], 'auto_extras' => false]))->discover($this->root);
        $byName = array_column($found, null, 'name');

        $this->assertSame(realpath($this->root . '/vendor/evolution-cms/evolution/core'), realpath($byName['evolution-cms']['path']));
        $this->assertArrayHasKey('local/snippets', $byName);
    }

    public function testNothingToDiscoverIsAnEmptyList(): void
    {
        $this->dirs('empty');

        $this->assertSame([], (new SourceDiscovery(['auto_extras' => false, 'local_paths' => []]))->discover($this->root . '/empty'));
    }

    public function testDocumentationSourcesComeFromConfigAbsoluteOrRelativeToTheSite(): void
    {
        $this->dirs('core/src', 'docs-rel/en', 'elsewhere');
        file_put_contents($this->root . '/core/src/Core.php', '<?php class Core {}');

        $config = ['auto_extras' => false, 'local_paths' => [], 'docs_paths' => [
            ['name' => 'evo-docs', 'path' => 'docs-rel/en', 'lang' => 'en'],
            ['path' => $this->root . '/elsewhere', 'lang' => 'ru'],
            ['name' => 'gone', 'path' => $this->root . '/missing'],
        ]];
        $byName = array_column((new SourceDiscovery($config))->discover($this->root . '/core'), null, 'name');

        $this->assertSame('docs', $byName['evo-docs']['layer']);
        $this->assertSame('en', $byName['evo-docs']['lang']);
        $this->assertSame(realpath($this->root . '/docs-rel/en'), realpath($byName['evo-docs']['path']));
        $this->assertSame('ru', $byName['elsewhere']['lang']);
        $this->assertArrayNotHasKey('gone', $byName);
    }
}
