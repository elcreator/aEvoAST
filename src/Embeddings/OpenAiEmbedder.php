<?php

namespace Elcreator\aEvoAST\Embeddings;

/**
 * Any server speaking OpenAI's POST {url}/embeddings: OpenAI itself, an OpenAI-compatible
 * gateway, OpenRouter, or a local LM Studio / llama.cpp server.
 */
class OpenAiEmbedder implements Embedder
{
    private const KNOWN_DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    private string $url;
    /** @var callable|null fn(string $url, array $headers, string $body): array{0:int,1:string} */
    private $transport;
    private int $dimensions;

    public function __construct(
        string $url,
        private string $model,
        private ?string $key = null,
        private ?int $requestedDimensions = null,
        private int $maxBatch = 256,
        private int $retries = 2,
        private int $retryDelayMs = 500,
        ?callable $transport = null,
    ) {
        $this->url = rtrim($url, '/');
        $this->transport = $transport;
        $this->dimensions = $requestedDimensions ?: (self::KNOWN_DIMENSIONS[$model] ?? 0);
    }

    public function getDriver(): string
    {
        return 'openai';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    public function isRemote(): bool
    {
        return !HostCheck::isPrivate($this->url);
    }

    /** Configuration check only: probing would spend a request. */
    public function isAvailable(): bool
    {
        if ($this->url === '' || $this->model === '') {
            return false;
        }

        return !$this->isRemote() || ($this->key !== null && $this->key !== '');
    }

    public function embedBatch(array $texts): array
    {
        $texts = array_values($texts);
        $out = [];

        foreach (array_chunk($texts, max(1, $this->maxBatch)) as $part) {
            foreach ($this->request($part) as $vector) {
                $out[] = $vector;
            }
        }

        return $out;
    }

    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * @param  string[]  $texts
     * @return float[][]
     */
    private function request(array $texts): array
    {
        $body = ['model' => $this->model, 'input' => $texts];
        if ($this->requestedDimensions) {
            $body['dimensions'] = $this->requestedDimensions;
        }

        $headers = ['Content-Type: application/json'];
        if ($this->key !== null && $this->key !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $attempt = 0;

        while (true) {
            [$status, $response] = $this->send($this->url . '/embeddings', $headers, $payload);

            // 0 = no response at all; 408, 429 and 5xx (but not 501) are worth another try.
            $transient = $status === 0 || $status === 408 || $status === 429 || ($status >= 500 && $status !== 501);
            if ($transient && $attempt < $this->retries) {
                $attempt++;
                if ($this->retryDelayMs > 0) {
                    usleep($this->retryDelayMs * 1000 * $attempt);
                }
                continue;
            }

            break;
        }

        if ($status !== 200) {
            throw new \RuntimeException("Embeddings endpoint returned HTTP {$status}: " . $this->excerpt($response));
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            throw new \RuntimeException('Unexpected embeddings response: missing "data"');
        }

        $rows = $data['data'];
        usort($rows, fn($a, $b) => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));

        if (count($rows) !== count($texts)) {
            throw new \RuntimeException(sprintf(
                'Embeddings response has %d vectors for %d inputs', count($rows), count($texts)
            ));
        }

        $vectors = [];
        foreach ($rows as $row) {
            if (!isset($row['embedding']) || !is_array($row['embedding'])) {
                throw new \RuntimeException('Unexpected embeddings response: a row has no "embedding"');
            }
            $vectors[] = array_map('floatval', $row['embedding']);
        }

        if ($vectors !== []) {
            $this->dimensions = count($vectors[0]);
        }

        return $vectors;
    }

    /** @return array{0:int,1:string} */
    private function send(string $url, array $headers, string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $headers, $body);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$response === false ? 0 : $status, $response === false ? '' : (string) $response];
    }

    private function excerpt(string $body): string
    {
        $body = trim($body);

        return strlen($body) > 300 ? substr($body, 0, 300) . '...' : $body;
    }
}
