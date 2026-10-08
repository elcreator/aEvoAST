<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Embeddings\OpenAiEmbedder;
use PHPUnit\Framework\TestCase;

class OpenAiEmbedderTest extends TestCase
{
    /** A transport that records requests and answers from a queue of [status, body]. */
    private function transport(array &$log, array $answers): callable
    {
        return function (string $url, array $headers, string $body) use (&$log, &$answers): array {
            $log[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];

            return array_shift($answers) ?? [500, ''];
        };
    }

    private function ok(array $vectors, bool $shuffle = false): array
    {
        $rows = [];
        foreach ($vectors as $i => $v) {
            $rows[] = ['object' => 'embedding', 'index' => $i, 'embedding' => $v];
        }
        if ($shuffle) {
            $rows = array_reverse($rows);
        }

        return [200, json_encode(['object' => 'list', 'data' => $rows])];
    }

    private function make(array &$log, array $answers, array $args = []): OpenAiEmbedder
    {
        return new OpenAiEmbedder(
            $args['url'] ?? 'https://gw.example/v1/',
            $args['model'] ?? 'text-embedding-3-small',
            array_key_exists('key', $args) ? $args['key'] : 'sk-secret',
            $args['dimensions'] ?? null,
            $args['maxBatch'] ?? 256,
            retries: $args['retries'] ?? 2,
            retryDelayMs: 0,
            transport: $this->transport($log, $answers),
        );
    }

    public function testPostsToEmbeddingsWithBearerKeyAndReturnsVectors(): void
    {
        $log = [];
        $e = $this->make($log, [$this->ok([[1.0, 0.0], [0.0, 1.0]])]);

        $out = $e->embedBatch(['a', 'b']);

        $this->assertSame([[1.0, 0.0], [0.0, 1.0]], $out);
        $this->assertSame('https://gw.example/v1/embeddings', $log[0]['url']);
        $this->assertContains('Authorization: Bearer sk-secret', $log[0]['headers']);
        $this->assertSame(['model' => 'text-embedding-3-small', 'input' => ['a', 'b']], $log[0]['body']);
        $this->assertSame(2, $e->getDimensions(), 'the size comes from what the server returned');
    }

    public function testRowsAreReorderedByIndex(): void
    {
        $log = [];
        $e = $this->make($log, [$this->ok([[1.0], [2.0], [3.0]], shuffle: true)]);

        $this->assertSame([[1.0], [2.0], [3.0]], $e->embedBatch(['a', 'b', 'c']));
    }

    public function testBatchesAreSplitAndRejoinedInOrder(): void
    {
        $log = [];
        $e = $this->make($log, [$this->ok([[1.0], [2.0]]), $this->ok([[3.0]])], ['maxBatch' => 2]);

        $this->assertSame([[1.0], [2.0], [3.0]], $e->embedBatch(['a', 'b', 'c']));
        $this->assertCount(2, $log);
        $this->assertSame(['c'], $log[1]['body']['input']);
    }

    public function testEmptyInputMakesNoRequest(): void
    {
        $log = [];
        $this->assertSame([], $this->make($log, [])->embedBatch([]));
        $this->assertSame([], $log);
    }

    public function testDimensionsAreSentOnlyWhenRequested(): void
    {
        $log = [];
        $e = $this->make($log, [$this->ok([[1.0]])], ['dimensions' => 256]);
        $this->assertSame(256, $e->getDimensions());
        $e->embed('x');

        $this->assertSame(256, $log[0]['body']['dimensions']);
    }

    public function testKnownModelsReportTheirSizeBeforeAnyRequest(): void
    {
        $log = [];
        $this->assertSame(1536, $this->make($log, [])->getDimensions());
        $this->assertSame(3072, $this->make($log, [], ['model' => 'text-embedding-3-large'])->getDimensions());
        $this->assertSame(0, $this->make($log, [], ['model' => 'some-local-model'])->getDimensions());
    }

    public function testNoAuthorizationHeaderWithoutAKey(): void
    {
        $log = [];
        $e = $this->make($log, [$this->ok([[1.0]])], ['url' => 'http://localhost:1234/v1', 'key' => null]);
        $e->embed('x');

        foreach ($log[0]['headers'] as $h) {
            $this->assertStringStartsNotWith('Authorization', $h);
        }
    }

    public function testTransientFailureIsRetriedThenSucceeds(): void
    {
        $log = [];
        $e = $this->make($log, [[503, 'busy'], [429, 'slow down'], $this->ok([[1.0]])]);

        $this->assertSame([[1.0]], $e->embedBatch(['a']));
        $this->assertCount(3, $log);
    }

    public function testClientErrorIsNotRetriedAndNeverEchoesTheKey(): void
    {
        $log = [];
        $e = $this->make($log, [[401, '{"error":"bad key"}']]);

        try {
            $e->embedBatch(['a']);
            $this->fail('expected an exception');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('HTTP 401', $ex->getMessage());
            $this->assertStringNotContainsString('sk-secret', $ex->getMessage());
        }
        $this->assertCount(1, $log);
    }

    public function testGivesUpAfterTheRetryBudget(): void
    {
        $log = [];
        $e = $this->make($log, [[500, 'x'], [500, 'x'], [500, 'x'], [500, 'x']]);

        $this->expectException(\RuntimeException::class);
        try {
            $e->embedBatch(['a']);
        } finally {
            $this->assertCount(3, $log, 'one try plus two retries');
        }
    }

    public function testWrongNumberOfVectorsIsAnError(): void
    {
        $log = [];
        $e = $this->make($log, [$this->ok([[1.0]])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('1 vectors for 2 inputs');
        $e->embedBatch(['a', 'b']);
    }

    public function testMalformedBodyIsAnError(): void
    {
        $log = [];
        $e = $this->make($log, [[200, '{"nope":1}']]);

        $this->expectException(\RuntimeException::class);
        $e->embedBatch(['a']);
    }

    public function testRemoteness(): void
    {
        $log = [];
        $this->assertTrue($this->make($log, [], ['url' => 'https://api.openai.com/v1'])->isRemote());
        $this->assertTrue($this->make($log, [], ['url' => 'https://gw.example/v1'])->isRemote());
        $this->assertFalse($this->make($log, [], ['url' => 'http://localhost:1234/v1'])->isRemote());
        $this->assertFalse($this->make($log, [], ['url' => 'http://192.168.88.41:1234/v1'])->isRemote());
        $this->assertFalse($this->make($log, [], ['url' => 'http://[::1]:8080/v1'])->isRemote());
        $this->assertTrue($this->make($log, [], ['url' => 'http://8.8.8.8/v1'])->isRemote());
    }

    public function testRemoteEmbedderIsUnavailableWithoutAKeyLocalOneIsNot(): void
    {
        $log = [];
        $this->assertFalse($this->make($log, [], ['key' => null])->isAvailable());
        $this->assertTrue($this->make($log, [])->isAvailable());
        $this->assertTrue($this->make($log, [], ['url' => 'http://localhost:1234/v1', 'key' => null])->isAvailable());
    }
}
