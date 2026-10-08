<?php

namespace Elcreator\aEvoAST\Embeddings;

/** Puts a vector on every chunk, a batch at a time. */
final class ChunkEmbedder
{
    /** Longest text sent for one chunk; documentation sections can be pages. */
    private const MAX_CHARS = 4000;

    /**
     * @param  array          $chunks
     * @param  callable|null  $onBatch  fn(int $done, int $total, ?string $error): void, called after each batch
     * @param  bool           $strict   throw on the first failed batch instead of leaving those chunks without a vector
     * @return array chunks with an 'embedding'
     *
     * @throws \RuntimeException in strict mode
     */
    public function embed(array $chunks, Embedder $embedder, int $batchSize = 32, ?callable $onBatch = null, bool $strict = false): array
    {
        $total = count($chunks);
        $batchSize = max(1, $batchSize);

        for ($i = 0; $i < $total; $i += $batchSize) {
            $batch = array_slice($chunks, $i, $batchSize);
            $error = null;

            try {
                $vectors = $embedder->embedBatch(array_map(fn($c) => mb_substr($c['text'], 0, self::MAX_CHARS), $batch));
                if (count($vectors) !== count($batch)) {
                    throw new \RuntimeException(sprintf('Asked for %d vectors, got %d', count($batch), count($vectors)));
                }
                foreach ($vectors as $j => $vector) {
                    $chunks[$i + $j]['embedding'] = $vector;
                }
            } catch (\Throwable $e) {
                if ($strict) {
                    throw new \RuntimeException("Embedding failed at chunk {$i}: {$e->getMessage()}", 0, $e);
                }
                $error = $e->getMessage();
                foreach (array_keys($batch) as $j) {
                    $chunks[$i + $j]['embedding'] = [];
                }
            }

            if ($onBatch !== null) {
                $onBatch(min($i + $batchSize, $total), $total, $error);
            }
        }

        return $chunks;
    }
}
