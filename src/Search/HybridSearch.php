<?php

namespace Elcreator\aEvoAST\Search;

use Elcreator\aEvoAST\Storage\VectorSearch;

/**
 * Lexical (BM25 over identifier words) and vector (cosine) rankings, fused by reciprocal
 * rank. Signatures embed poorly ("clear the cache" lands on delete() methods) while
 * their names match exactly; prose is the other way round. Each covers the other.
 *
 * Without a query vector (no embedder, or an index made without one) it is lexical only.
 */
final class HybridSearch
{
    public const MODES = ['hybrid', 'vector', 'lexical'];

    public function __construct(private int $rrfK = 60, private int $depth = 100)
    {
    }

    /**
     * @param  float[]|null  $queryEmbedding
     * @param  array         $chunks  each: id, text, meta, embedding?
     * @param  array<int, float>|null  $vectorScores  cosine per chunk key, already computed while the
     *         index was streamed in, so the vectors themselves never had to be held in memory
     * @return array<int, array{id: string, text: string, meta: array, score: float, vector: ?float, lexical: ?float}>
     */
    public function search(
        string  $query,
        ?array  $queryEmbedding,
        array   $chunks,
        int     $topK = 10,
        bool    $activeOnly = true,
        array   $filterMeta = [],
        string  $mode = 'hybrid',
        float   $vectorWeight = 1.0,
        float   $lexicalWeight = 1.0,
        ?array  $vectorScores = null,
    ): array {
        $allowed = [];
        foreach ($chunks as $i => $chunk) {
            if ($activeOnly && ($chunk['meta']['overridden'] ?? false)) {
                continue;
            }
            foreach ($filterMeta as $key => $value) {
                if (($chunk['meta'][$key] ?? null) !== $value) {
                    continue 2;
                }
            }
            $allowed[$i] = true;
        }

        $vector = [];
        if ($mode !== 'lexical' && $vectorScores !== null) {
            $vector = array_intersect_key($vectorScores, $allowed);
        } elseif ($mode !== 'lexical' && $queryEmbedding) {
            $cosine = new VectorSearch();
            foreach ($allowed as $i => $_) {
                if (!empty($chunks[$i]['embedding'])) {
                    $vector[$i] = $cosine->cosineSimilarity($queryEmbedding, $chunks[$i]['embedding']);
                }
            }
        }
        if ($vector) {
            arsort($vector);
            $vector = array_slice($vector, 0, $this->depth, true);
        }

        $lexical = [];
        if ($mode !== 'vector') {
            $docs = [];
            foreach ($chunks as $i => $chunk) {
                $docs[$i] = self::documentTokens($chunk);
            }
            $lexical = array_slice((new Bm25($docs))->search(Tokenizer::tokens($query), $allowed), 0, $this->depth, true);
        }

        $fused = [];
        $rank = 0;
        foreach ($vector as $i => $_) {
            $fused[$i] = ($fused[$i] ?? 0.0) + $vectorWeight / ($this->rrfK + ++$rank);
        }
        $rank = 0;
        foreach ($lexical as $i => $_) {
            $fused[$i] = ($fused[$i] ?? 0.0) + $lexicalWeight / ($this->rrfK + ++$rank);
        }
        arsort($fused);

        $out = [];
        foreach (array_slice($fused, 0, $topK, true) as $i => $score) {
            $out[] = [
                'id'      => $chunks[$i]['id'],
                'text'    => $chunks[$i]['text'],
                'meta'    => $chunks[$i]['meta'],
                'score'   => $score,
                'vector'  => $vector[$i] ?? null,
                'lexical' => $lexical[$i] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The words of a chunk: its text, plus its own name twice more, because a method called
     * clearCache is about caches whatever the docblock says.
     *
     * @return string[]
     */
    public static function documentTokens(array $chunk): array
    {
        $meta = $chunk['meta'] ?? [];
        $name = (string) ($meta['method'] ?? $meta['name'] ?? $meta['heading'] ?? '');
        $tokens = Tokenizer::tokens((string) ($chunk['text'] ?? ''));

        if ($name !== '') {
            $nameTokens = Tokenizer::tokens($name);
            array_push($tokens, ...$nameTokens, ...$nameTokens);
        }

        return $tokens;
    }
}
