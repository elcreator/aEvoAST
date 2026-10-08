<?php

namespace Elcreator\aEvoAST\Storage;

class VectorSearch
{
    /**
     * Search chunks by cosine similarity to a query embedding.
     *
     * @param  float[] $queryEmbedding
     * @param  array   $chunks           Each chunk must have 'embedding', 'id', 'text', 'meta'
     * @param  int     $topK             Number of results to return
     * @param  bool    $activeOnly       Skip overridden chunks
     * @param  array   $filterMeta       e.g. ['source' => 'my-plugin'] to filter by metadata
     * @return array   Top K results with 'score' appended
     */
    public function search(
        array $queryEmbedding,
        array $chunks,
        int   $topK = 10,
        bool  $activeOnly = true,
        array $filterMeta = [],
    ): array {
        $results = [];

        foreach ($chunks as $chunk) {
            if (empty($chunk['embedding'])) {
                continue;
            }

            if ($activeOnly && ($chunk['meta']['overridden'] ?? false)) {
                continue;
            }

            // Apply metadata filters
            if (!empty($filterMeta)) {
                $match = true;
                foreach ($filterMeta as $key => $value) {
                    if (($chunk['meta'][$key] ?? null) !== $value) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) continue;
            }

            $score = $this->cosineSimilarity($queryEmbedding, $chunk['embedding']);

            $results[] = [
                'id'    => $chunk['id'],
                'text'  => $chunk['text'],
                'meta'  => $chunk['meta'],
                'score' => $score,
            ];
        }

        // Sort by score descending
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $topK);
    }

    /**
     * Cosine similarity between two vectors.
     *
     * Both vectors are assumed to be L2-normalized (Ollama /api/embed does this),
     * so cosine similarity = dot product.
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
