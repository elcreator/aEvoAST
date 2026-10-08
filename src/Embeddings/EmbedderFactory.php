<?php

namespace Elcreator\aEvoAST\Embeddings;

class EmbedderFactory
{
    /**
     * @param  array  $config  the `aevoast` config array
     */
    public static function make(array $config): Embedder
    {
        $driver = strtolower((string) ($config['embedder'] ?? 'ollama'));

        return match ($driver) {
            'ollama' => new OllamaEmbedder(
                (string) ($config['ollama']['url'] ?? 'http://localhost:11434'),
                (string) ($config['ollama']['model'] ?? 'bge-m3'),
            ),
            'openai' => new OpenAiEmbedder(
                (string) ($config['openai']['url'] ?? 'https://api.openai.com/v1'),
                (string) ($config['openai']['model'] ?? 'text-embedding-3-small'),
                ($config['openai']['key'] ?? null) ?: null,
                ($config['openai']['dimensions'] ?? null) ? (int) $config['openai']['dimensions'] : null,
            ),
            default => throw new \InvalidArgumentException(
                "Unknown embedder \"{$driver}\" (aevoast.embedder): use \"ollama\" or \"openai\""
            ),
        };
    }

    /** Whether project code of the "local" layer may be sent to this embedder. */
    public static function mayEmbedLocal(Embedder $embedder, array $config): bool
    {
        return !$embedder->isRemote() || (bool) ($config['allow_remote_local'] ?? false);
    }
}
