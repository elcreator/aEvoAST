<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Search\Bm25;
use Elcreator\aEvoAST\Search\HybridSearch;
use Elcreator\aEvoAST\Search\Tokenizer;
use PHPUnit\Framework\TestCase;

class SearchTest extends TestCase
{
    public function testIdentifiersAndProseGiveTheSameWords(): void
    {
        $this->assertSame(['core', 'clear', 'cache'], Tokenizer::tokens('Core::clearCache'));
        $this->assertSame(['clear', 'cache'], Tokenizer::tokens('clear the cache'));
        $this->assertSame(['get', 'login', 'user', 'id'], Tokenizer::tokens('getLoginUserID'));
        $this->assertSame(['parse', 'html', 'string'], Tokenizer::tokens('parse_HTMLString'));
    }

    public function testStemmingIsLightAndConsistent(): void
    {
        $this->assertSame(Tokenizer::tokens('variables'), Tokenizer::tokens('variable'));
        $this->assertSame(Tokenizer::tokens('categories'), Tokenizer::tokens('category'));
        $this->assertSame(Tokenizer::tokens('redirecting'), Tokenizer::tokens('redirect'));
        $this->assertSame(['class'], Tokenizer::tokens('class'), 'ss is not a plural');
        $this->assertSame(['status'], Tokenizer::tokens('status'), 'us is not a plural');
    }

    public function testBm25RanksTheRarerMatchFirstAndSkipsNonMatches(): void
    {
        $bm = new Bm25([
            0 => ['cache', 'clear', 'site'],
            1 => ['cache', 'user'],
            2 => ['user', 'login'],
        ]);

        $scores = $bm->search(['clear', 'cache']);
        $this->assertSame([0, 1], array_keys($scores));
        $this->assertArrayNotHasKey(2, $scores);
        $this->assertSame([], $bm->search(['nothing']));
    }

    public function testBm25CanBeRestrictedToAllowedDocuments(): void
    {
        $bm = new Bm25([0 => ['cache'], 1 => ['cache']]);

        $this->assertSame([1], array_keys($bm->search(['cache'], [1 => true])));
    }

    private function chunks(): array
    {
        return [
            ['id' => 'a', 'text' => 'EvolutionCMS\Core::clearCache($type) [public] - Clear the cache of MODX.', 'meta' => ['method' => 'clearCache', 'layer' => 'core'], 'embedding' => [0.0, 1.0]],
            ['id' => 'b', 'text' => 'EvolutionCMS\Models\User::delete() [public]', 'meta' => ['method' => 'delete', 'layer' => 'core'], 'embedding' => [1.0, 0.0]],
            ['id' => 'c', 'text' => 'EvolutionCMS\Core::clearCache($type) [public] - local override', 'meta' => ['method' => 'clearCache', 'layer' => 'local', 'overridden' => true], 'embedding' => [0.0, 1.0]],
        ];
    }

    public function testLexicalFindsWhatTheVectorMisses(): void
    {
        // The query vector points at "delete" (the failure seen on real signatures), the words at clearCache.
        $h = new HybridSearch();

        $vector = $h->search('clear the cache', [1.0, 0.0], $this->chunks(), 3, mode: 'vector');
        $this->assertSame('b', $vector[0]['id']);

        $hybrid = $h->search('clear the cache', [1.0, 0.0], $this->chunks(), 3);
        $this->assertSame(['a', 'b'], array_column($hybrid, 'id'), 'both rankings contribute, the overridden chunk is hidden');
        $this->assertNotNull($hybrid[0]['lexical']);
    }

    public function testLexicalModeNeedsNoVectors(): void
    {
        $chunks = array_map(fn($c) => array_replace($c, ['embedding' => []]), $this->chunks());

        $hits = (new HybridSearch())->search('clear cache', null, $chunks, 5);

        $this->assertSame(['a'], array_column($hits, 'id'));
        $this->assertNull($hits[0]['vector']);
    }

    public function testFiltersAndOverriddenFlag(): void
    {
        $h = new HybridSearch();

        $this->assertSame(['c'], array_column($h->search('clear cache', null, $this->chunks(), 5, activeOnly: false, filterMeta: ['layer' => 'local']), 'id'));
        $this->assertSame([], $h->search('clear cache', null, $this->chunks(), 5, filterMeta: ['layer' => 'local']));
    }

    public function testAnEmptyQueryOrIndexIsEmpty(): void
    {
        $h = new HybridSearch();
        $this->assertSame([], $h->search('', null, $this->chunks()));
        $this->assertSame([], $h->search('cache', [1.0, 0.0], []));
    }

    public function testPrecomputedVectorScoresGiveTheSameRankingAsEmbeddings(): void
    {
        $h = new HybridSearch();
        $chunks = $this->chunks();

        $scores = [];
        foreach ($chunks as $i => $c) {
            $scores[$i] = (new \Elcreator\aEvoAST\Storage\VectorSearch())->cosineSimilarity([1.0, 0.0], $c['embedding']);
            unset($chunks[$i]['embedding']);
        }

        $this->assertSame(
            array_column($h->search('clear the cache', [1.0, 0.0], $this->chunks(), 3), 'id'),
            array_column($h->search('clear the cache', null, $chunks, 3, vectorScores: $scores), 'id'),
        );
    }

    public function testScoresOfChunksOutsideTheFilterAreIgnored(): void
    {
        $chunks = $this->chunks();
        $hits = (new HybridSearch())->search('x', null, $chunks, 5, filterMeta: ['layer' => 'local'], activeOnly: false, mode: 'vector', vectorScores: [0 => 0.9, 1 => 0.8, 2 => 0.1]);

        $this->assertSame(['c'], array_column($hits, 'id'));
    }
}
