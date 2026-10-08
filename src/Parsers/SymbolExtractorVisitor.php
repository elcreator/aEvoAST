<?php

namespace Elcreator\aEvoAST\Parsers;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;
use PhpParser\Node\Stmt;

class SymbolExtractorVisitor extends NodeVisitorAbstract
{
    /** @var array<int, array<string, mixed>> */
    private array $symbols = [];

    private ?string $currentNamespace = null;

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString();
            return null;
        }

        if ($node instanceof Stmt\Class_
            || $node instanceof Stmt\Interface_
            || $node instanceof Stmt\Trait_
            || $node instanceof Stmt\Enum_
        ) {
            $this->symbols[] = $this->extractClassLike($node);
            return null;
        }

        // Top-level functions (snippets, helpers)
        if ($node instanceof Stmt\Function_) {
            $this->symbols[] = $this->extractFunction($node);
            return null;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSymbols(): array
    {
        return $this->symbols;
    }

    public function reset(): void
    {
        $this->symbols = [];
        $this->currentNamespace = null;
    }

    private function extractClassLike(Stmt\Class_|Stmt\Interface_|Stmt\Trait_|Stmt\Enum_ $node): array
    {
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_     => 'trait',
            $node instanceof Stmt\Enum_      => 'enum',
            default                          => 'class',
        };

        $symbol = [
            'kind'       => $kind,
            'namespace'  => $this->currentNamespace,
            'name'       => $node->name?->toString() ?? '(anonymous)',
            'fqcn'       => $this->fqcn($node->name?->toString()),
            'summary'    => $this->summary($node),
            'extends'    => null,
            'implements' => [],
            'constants'  => [],
            'properties' => [],
            'methods'    => [],
            'line_start' => $node->getStartLine(),
            'line_end'   => $node->getEndLine(),
        ];

        if ($node instanceof Stmt\Class_) {
            $symbol['extends'] = $node->extends?->toString();
            $symbol['implements'] = array_map(
                fn($n) => $n->toString(),
                $node->implements
            );
        }

        if ($node instanceof Stmt\Interface_) {
            $symbol['extends'] = array_map(
                fn($n) => $n->toString(),
                $node->extends
            );
        }

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\ClassMethod) {
                $symbol['methods'][] = $this->extractMethod($stmt);
            } elseif ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $symbol['properties'][] = [
                        'name'       => $prop->name->toString(),
                        'type'       => $this->typeToString($stmt->type),
                        'visibility' => $this->visibility($stmt),
                        'static'     => $stmt->isStatic(),
                        'line'       => $stmt->getStartLine(),
                    ];
                }
            } elseif ($stmt instanceof Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $symbol['constants'][] = [
                        'name'       => $const->name->toString(),
                        'visibility' => $this->visibility($stmt),
                        'line'       => $stmt->getStartLine(),
                    ];
                }
            }
        }

        return $symbol;
    }

    private function extractMethod(Stmt\ClassMethod $node): array
    {
        return [
            'name'        => $node->name->toString(),
            'summary'     => $this->summary($node),
            'visibility'  => $this->methodVisibility($node),
            'static'      => $node->isStatic(),
            'abstract'    => $node->isAbstract(),
            'return_type' => $this->typeToString($node->returnType),
            'params'      => $this->extractParams($node->params),
            'line_start'  => $node->getStartLine(),
            'line_end'    => $node->getEndLine(),
        ];
    }

    private function extractFunction(Stmt\Function_ $node): array
    {
        return [
            'kind'        => 'function',
            'namespace'   => $this->currentNamespace,
            'name'        => $node->name->toString(),
            'fqcn'        => $this->fqcn($node->name->toString()),
            'summary'     => $this->summary($node),
            'return_type' => $this->typeToString($node->returnType),
            'params'      => $this->extractParams($node->params),
            'line_start'  => $node->getStartLine(),
            'line_end'    => $node->getEndLine(),
        ];
    }

    /**
     * First sentence of the docblock: what the symbol is for, in the author's words.
     * Tags (@param, @return ...) and everything after the first blank line are left out.
     */
    private function summary(Node $node): ?string
    {
        $doc = $node->getDocComment();
        if ($doc === null) {
            return null;
        }

        $lines = [];
        foreach (preg_split('/\R/', $doc->getText()) as $line) {
            $line = trim(preg_replace('#^\s*(/\*\*+|\*+/|\*)#', '', $line));
            $line = trim(preg_replace('#\*+/\s*$#', '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '') {
                if ($lines) {
                    break;
                }
                continue;
            }
            $lines[] = $line;
        }

        $text = trim(implode(' ', $lines));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(.+?[.!?])(\s|$)/', $text, $m)) {
            $text = $m[1];
        }

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 197) . '...' : $text;
    }

    /**
     * @param Node\Param[] $params
     */
    private function extractParams(array $params): array
    {
        return array_map(function (Node\Param $param) {
            return [
                'name'     => '$' . ($param->var instanceof Node\Expr\Variable ? $param->var->name : '?'),
                'type'     => $this->typeToString($param->type),
                'default'  => $param->default !== null,
                'variadic' => $param->variadic,
                'byRef'    => $param->byRef,
            ];
        }, $params);
    }

    private function typeToString(?Node $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof Node\Identifier) {
            return $type->toString();
        }

        if ($type instanceof Node\Name) {
            return $type->toString();
        }

        if ($type instanceof Node\NullableType) {
            return '?' . $this->typeToString($type->type);
        }

        if ($type instanceof Node\UnionType) {
            return implode('|', array_map(
                fn($t) => $this->typeToString($t),
                $type->types
            ));
        }

        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map(
                fn($t) => $this->typeToString($t),
                $type->types
            ));
        }

        return (string) $type;
    }

    private function visibility(Stmt\Property|Stmt\ClassConst $node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isProtected()) return 'protected';
        if ($node->isPrivate()) return 'private';
        return 'public';
    }

    private function methodVisibility(Stmt\ClassMethod $node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isProtected()) return 'protected';
        if ($node->isPrivate()) return 'private';
        return 'public';
    }

    private function fqcn(?string $name): ?string
    {
        if ($name === null) return null;
        return $this->currentNamespace
            ? $this->currentNamespace . '\\' . $name
            : $name;
    }
}
