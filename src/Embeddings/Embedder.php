<?php

namespace Elcreator\aEvoAST\Embeddings;

interface Embedder
{
    /** Short driver name, e.g. "ollama" or "openai". Informational only. */
    public function getDriver(): string;

    /** Model name. Together with the dimensions it identifies the vector space. */
    public function getModel(): string;

    /** Vector length, or 0 when it is not known until the first request. */
    public function getDimensions(): int;

    /**
     * True when texts leave this machine (anything but loopback / private network).
     * Remote embedders do not see the "local" layer unless explicitly allowed.
     */
    public function isRemote(): bool;

    public function isAvailable(): bool;

    /**
     * @param  string[]  $texts
     * @return float[][]
     *
     * @throws \RuntimeException
     */
    public function embedBatch(array $texts): array;

    /** @return float[] */
    public function embed(string $text): array;
}
