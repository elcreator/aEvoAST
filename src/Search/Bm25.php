<?php

namespace Elcreator\aEvoAST\Search;

/** Okapi BM25 over pre-tokenised documents. Needs no embedder, no network, no model. */
final class Bm25
{
    /** @var array<string, array<int, int>> term => [doc => term frequency] */
    private array $postings = [];
    /** @var int[] */
    private array $lengths = [];
    private float $avgLength = 0.0;

    /**
     * @param  array<int, string[]>  $documents  token lists, keyed like the caller's documents
     */
    public function __construct(array $documents, private float $k1 = 1.2, private float $b = 0.75)
    {
        $total = 0;
        foreach ($documents as $doc => $tokens) {
            $this->lengths[$doc] = count($tokens);
            $total += count($tokens);
            foreach (array_count_values($tokens) as $term => $tf) {
                $this->postings[$term][$doc] = $tf;
            }
        }
        $this->avgLength = $documents ? $total / count($documents) : 0.0;
    }

    /**
     * @param  string[]  $query
     * @param  array<int, true>|null  $allowed  restrict to these documents
     * @return array<int, float> doc => score, best first, zero scores left out
     */
    public function search(array $query, ?array $allowed = null): array
    {
        $n = count($this->lengths);
        $scores = [];

        foreach (array_unique($query) as $term) {
            $posting = $this->postings[$term] ?? null;
            if ($posting === null) {
                continue;
            }
            $df = count($posting);
            $idf = log(1 + ($n - $df + 0.5) / ($df + 0.5));

            foreach ($posting as $doc => $tf) {
                if ($allowed !== null && !isset($allowed[$doc])) {
                    continue;
                }
                $norm = $tf + $this->k1 * (1 - $this->b + $this->b * $this->lengths[$doc] / max(1.0, $this->avgLength));
                $scores[$doc] = ($scores[$doc] ?? 0.0) + $idf * $tf * ($this->k1 + 1) / $norm;
            }
        }

        arsort($scores);

        return $scores;
    }
}
