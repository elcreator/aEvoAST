<?php

namespace Elcreator\aEvoAST\Parsers;

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SymbolMapParser
{
    private const SKIP_DIRS = ['vendor', 'node_modules', '.git'];

    private Parser $parser;
    private SymbolExtractorVisitor $visitor;
    private NodeTraverser $traverser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->visitor = new SymbolExtractorVisitor();
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor($this->visitor);
    }

    /**
     * Parse a directory and return all symbols found.
     *
     * @param  string $path         Absolute path to scan
     * @param  string $layer        'core' | 'extra' | 'local'
     * @param  string $sourceName   e.g. 'evolution-cms', 'seiger/slang', 'my-plugin'
     * @return array{symbols: array, errors: array}
     */
    public function parseDirectory(string $path, string $layer, string $sourceName): array
    {
        $result = [
            'source'  => $sourceName,
            'layer'   => $layer,
            'path'    => $path,
            'symbols' => [],
            'errors'  => [],
        ];

        if (!is_dir($path)) {
            $result['errors'][] = "Directory not found: {$path}";
            return $result;
        }

        // A nested vendor/ is somebody else's dependencies (Laravel alone is ~36k chunks,
        // thirteen times Evo's own core): they are not part of this source.
        $iterator = new RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                fn(SplFileInfo $f) => !$f->isDir() || !in_array($f->getFilename(), self::SKIP_DIRS, true)
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = $this->relativePath($filePath, $path);

            try {
                $symbols = $this->parseFile($filePath);
                foreach ($symbols as &$symbol) {
                    $symbol['file'] = $relativePath;
                    $symbol['source'] = $sourceName;
                    $symbol['layer'] = $layer;
                }
                unset($symbol);

                $result['symbols'] = array_merge($result['symbols'], $symbols);
            } catch (\Throwable $e) {
                $result['errors'][] = "{$relativePath}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * Parse a single PHP file and return extracted symbols.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parseFile(string $filePath): array
    {
        $code = file_get_contents($filePath);
        if ($code === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        $this->visitor->reset();

        try {
            $stmts = $this->parser->parse($code);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Parse error: {$e->getMessage()}");
        }

        if ($stmts === null) {
            return [];
        }

        $this->traverser->traverse($stmts);

        return $this->visitor->getSymbols();
    }

    private function relativePath(string $filePath, string $basePath): string
    {
        $basePath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($filePath, $basePath)) {
            return substr($filePath, strlen($basePath));
        }
        return $filePath;
    }
}
