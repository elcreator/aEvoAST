<?php

namespace Elcreator\aEvoAST\Parsers;

class Chunker
{
    /**
     * Convert parsed symbols into embeddable text chunks.
     *
     * Each chunk has 'id', 'text', and 'meta' keys.
     * The 'text' is a human-readable signature string that embeds well.
     *
     * @param  array  $symbols  Output from SymbolMapParser
     * @param  string $chunkBy  'method' | 'class' | 'file'
     * @return array<int, array{id: string, text: string, meta: array}>
     */
    public function chunk(array $symbols, string $chunkBy = 'method'): array
    {
        $chunks = [];

        foreach ($symbols as $symbol) {
            $kind = $symbol['kind'] ?? 'unknown';

            if ($kind === 'function') {
                $chunks[] = $this->chunkFunction($symbol);
                continue;
            }

            // class, interface, trait, enum
            if ($chunkBy === 'class' || $chunkBy === 'file') {
                $chunks[] = $this->chunkWholeClass($symbol);
            } else {
                // method-level chunking: one chunk per method + one for class header
                $chunks[] = $this->chunkClassHeader($symbol);
                foreach ($symbol['methods'] ?? [] as $method) {
                    $chunks[] = $this->chunkMethod($symbol, $method);
                }
            }
        }

        return $chunks;
    }

    private function chunkFunction(array $symbol): array
    {
        $sig = $this->withSummary($this->functionSignature($symbol), $symbol['summary'] ?? null);
        $id = $this->makeId($symbol, null);

        return [
            'id'   => $id,
            'text' => $sig,
            'meta' => [
                'kind'       => 'function',
                'name'       => $symbol['name'],
                'fqcn'       => $symbol['fqcn'] ?? $symbol['name'],
                'file'       => $symbol['file'] ?? '',
                'source'     => $symbol['source'] ?? '',
                'layer'      => $symbol['layer'] ?? '',
                'line_start' => $symbol['line_start'] ?? 0,
                'line_end'   => $symbol['line_end'] ?? 0,
            ],
        ];
    }

    private function chunkClassHeader(array $symbol): array
    {
        $sig = $this->withSummary($this->classHeaderSignature($symbol), $symbol['summary'] ?? null);
        $id = $this->makeId($symbol, null);

        return [
            'id'   => $id,
            'text' => $sig,
            'meta' => [
                'kind'       => $symbol['kind'],
                'name'       => $symbol['name'],
                'fqcn'       => $symbol['fqcn'] ?? $symbol['name'],
                'file'       => $symbol['file'] ?? '',
                'source'     => $symbol['source'] ?? '',
                'layer'      => $symbol['layer'] ?? '',
                'line_start' => $symbol['line_start'] ?? 0,
                'line_end'   => $symbol['line_end'] ?? 0,
            ],
        ];
    }

    private function chunkMethod(array $classSymbol, array $method): array
    {
        $classFqcn = $classSymbol['fqcn'] ?? $classSymbol['name'];
        $text = "{$classFqcn}::{$method['name']}" .
                '(' . $this->paramsToString($method['params'] ?? []) . ')' .
                ($method['return_type'] ? ': ' . $method['return_type'] : '') .
                ' [' . $method['visibility'] .
                ($method['static'] ? ' static' : '') .
                ($method['abstract'] ? ' abstract' : '') .
                ']';
        $text = $this->withSummary($text, $method['summary'] ?? null);

        $id = $this->makeId($classSymbol, $method['name']);

        return [
            'id'   => $id,
            'text' => $text,
            'meta' => [
                'kind'        => 'method',
                'class'       => $classFqcn,
                'method'      => $method['name'],
                'file'        => $classSymbol['file'] ?? '',
                'source'      => $classSymbol['source'] ?? '',
                'layer'       => $classSymbol['layer'] ?? '',
                'visibility'  => $method['visibility'],
                'static'      => $method['static'],
                'line_start'  => $method['line_start'] ?? 0,
                'line_end'    => $method['line_end'] ?? 0,
            ],
        ];
    }

    private function chunkWholeClass(array $symbol): array
    {
        $lines = [$this->classHeaderSignature($symbol)];

        foreach ($symbol['properties'] ?? [] as $prop) {
            $lines[] = "  {$prop['visibility']} " .
                       ($prop['static'] ? 'static ' : '') .
                       ($prop['type'] ? $prop['type'] . ' ' : '') .
                       '$' . $prop['name'];
        }

        foreach ($symbol['methods'] ?? [] as $method) {
            $lines[] = "  {$method['visibility']} " .
                       ($method['static'] ? 'static ' : '') .
                       ($method['abstract'] ? 'abstract ' : '') .
                       $method['name'] .
                       '(' . $this->paramsToString($method['params'] ?? []) . ')' .
                       ($method['return_type'] ? ': ' . $method['return_type'] : '');
        }

        $id = $this->makeId($symbol, null);

        return [
            'id'   => $id,
            'text' => implode("\n", $lines),
            'meta' => [
                'kind'       => $symbol['kind'],
                'name'       => $symbol['name'],
                'fqcn'       => $symbol['fqcn'] ?? $symbol['name'],
                'file'       => $symbol['file'] ?? '',
                'source'     => $symbol['source'] ?? '',
                'layer'      => $symbol['layer'] ?? '',
                'line_start' => $symbol['line_start'] ?? 0,
                'line_end'   => $symbol['line_end'] ?? 0,
            ],
        ];
    }

    /** "signature - what it does": the words an author uses to describe it are the words a query uses. */
    private function withSummary(string $signature, ?string $summary): string
    {
        return $summary ? $signature . ' - ' . $summary : $signature;
    }

    private function classHeaderSignature(array $symbol): string
    {
        $sig = $symbol['kind'] . ' ' . ($symbol['fqcn'] ?? $symbol['name']);

        if (!empty($symbol['extends'])) {
            $extends = is_array($symbol['extends'])
                ? implode(', ', $symbol['extends'])
                : $symbol['extends'];
            $sig .= ' extends ' . $extends;
        }

        if (!empty($symbol['implements'])) {
            $sig .= ' implements ' . implode(', ', $symbol['implements']);
        }

        return $sig;
    }

    private function functionSignature(array $symbol): string
    {
        return 'function ' . ($symbol['fqcn'] ?? $symbol['name']) .
               '(' . $this->paramsToString($symbol['params'] ?? []) . ')' .
               ($symbol['return_type'] ? ': ' . $symbol['return_type'] : '');
    }

    private function paramsToString(array $params): string
    {
        return implode(', ', array_map(function (array $p) {
            $str = '';
            if ($p['type']) $str .= $p['type'] . ' ';
            if ($p['variadic']) $str .= '...';
            if ($p['byRef']) $str .= '&';
            $str .= $p['name'];
            if ($p['default']) $str .= ' = …';
            return $str;
        }, $params));
    }

    private function makeId(array $symbol, ?string $method): string
    {
        $source = $symbol['source'] ?? 'unknown';
        $fqcn = $symbol['fqcn'] ?? $symbol['name'];
        $id = "{$source}::{$fqcn}";
        if ($method !== null) {
            $id .= "::{$method}";
        }
        return $id;
    }
}
