<?php

namespace Elcreator\aEvoAST\Embeddings;

class OllamaEmbedder implements Embedder
{
    private const RETRIES = 3;

    private string $url;
    private string $model;
    private int $dimensions;

    public function __construct(string $url, string $model)
    {
        $this->url = rtrim($url, '/');
        $this->model = $model;
        // Sizes of the models we know; any other model reports its size on first use.
        $this->dimensions = str_starts_with($model, 'nomic-embed-text') ? 768
            : (str_starts_with($model, 'bge-m3') ? 1024 : 0);
    }

    public function getDriver(): string
    {
        return 'ollama';
    }

    public function isRemote(): bool
    {
        return !HostCheck::isPrivate($this->url);
    }

    /**
     * Check if Ollama is reachable and the model is available.
     */
    public function isAvailable(): bool
    {
        try {
            $ch = curl_init("{$this->url}/api/tags");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $response === false) {
                return false;
            }

            $data = json_decode($response, true);
            if (!isset($data['models'])) {
                return false;
            }

            foreach ($data['models'] as $m) {
                $name = $m['name'] ?? '';
                // Match "nomic-embed-text" or "nomic-embed-text:latest"
                if ($name === $this->model || str_starts_with($name, $this->model . ':')) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Embed a batch of text chunks.
     *
     * @param  string[]  $texts
     * @return float[][] Array of embedding vectors
     *
     * @throws \RuntimeException
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $payload = json_encode([
            'model' => $this->model,
            'input' => array_values($texts),
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        // A busy or CPU-only Ollama (a CI runner) can stall on a batch: try again before giving up.
        for ($attempt = 0; ; $attempt++) {
            $ch = curl_init("{$this->url}/api/embed");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 300,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $transient = $response === false || $httpCode === 408 || $httpCode === 429 || ($httpCode >= 500 && $httpCode !== 501);
            if (!$transient || $attempt >= self::RETRIES) {
                break;
            }
            sleep($attempt + 1);
        }

        if ($response === false) {
            throw new \RuntimeException("Ollama request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Ollama returned HTTP {$httpCode}: {$response}");
        }

        $data = json_decode($response, true);

        if (!isset($data['embeddings'])) {
            throw new \RuntimeException('Unexpected Ollama response: missing embeddings key');
        }

        $embeddings = $data['embeddings'];
        if (isset($embeddings[0]) && is_array($embeddings[0])) {
            $this->dimensions = count($embeddings[0]);
        }

        return $embeddings;
    }

    /**
     * Embed a single text string.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        $result = $this->embedBatch([$text]);
        return $result[0];
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }
}
