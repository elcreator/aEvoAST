<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Embeddings\Embedder;

/** An embedder that needs no server: a vector made from the text's length and first letter. */
class FakeEmbedder implements Embedder
{
    public function __construct(private string $model = 'bge-m3', private int $dims = 4)
    {
    }

    public function getDriver(): string { return 'ollama'; }
    public function getModel(): string { return $this->model; }
    public function getDimensions(): int { return $this->dims; }
    public function isRemote(): bool { return false; }
    public function isAvailable(): bool { return true; }

    public function embedBatch(array $texts): array
    {
        return array_map(fn($t) => array_pad([strlen($t) / 100, ord($t[0] ?? 'a') / 255], $this->dims, 0.5), $texts);
    }

    public function embed(string $text): array { return $this->embedBatch([$text])[0]; }
}
