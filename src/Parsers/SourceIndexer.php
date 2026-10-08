<?php

namespace Elcreator\aEvoAST\Parsers;

/**
 * One source (a code directory or a documentation tree) into symbols and chunks. Shared by
 * `ast:parse` and the release builder so a downloaded index and one built here are made alike.
 */
final class SourceIndexer
{
    public function __construct(
        private SymbolMapParser $parser = new SymbolMapParser(),
        private Chunker $chunker = new Chunker(),
        private MarkdownChunker $markdown = new MarkdownChunker(),
    ) {
    }

    /**
     * @param  array{path: string, layer: string, name: string, lang?: string}  $source
     * @return array{symbols: array, chunks: array, errors: string[]}
     */
    public function index(array $source, string $chunkBy = 'method'): array
    {
        if ($source['layer'] === 'docs') {
            $chunks = $this->markdown->chunkDirectory($source['path'], $source['name'], $source['lang'] ?? 'en');

            return [
                'symbols' => array_map(fn($c) => [
                    'kind'       => 'doc',
                    'name'       => $c['meta']['name'],
                    'heading'    => $c['meta']['heading'],
                    'file'       => $c['meta']['file'],
                    'source'     => $c['meta']['source'],
                    'layer'      => 'docs',
                    'lang'       => $c['meta']['lang'],
                    'line_start' => $c['meta']['line_start'],
                ], $chunks),
                'chunks' => $chunks,
                'errors' => [],
            ];
        }

        $result = $this->parser->parseDirectory($source['path'], $source['layer'], $source['name']);

        return [
            'symbols' => $result['symbols'],
            'chunks'  => $this->chunker->chunk($result['symbols'], $chunkBy),
            'errors'  => $result['errors'],
        ];
    }
}
