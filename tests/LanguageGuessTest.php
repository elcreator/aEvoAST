<?php

namespace Elcreator\aEvoAST\Tests;

use Elcreator\aEvoAST\Parsers\LanguageGuess;
use Elcreator\aEvoAST\Parsers\MarkdownChunker;
use PHPUnit\Framework\TestCase;

class LanguageGuessTest extends TestCase
{
    public function testEnglishProse(): void
    {
        $this->assertSame('en', LanguageGuess::of('Clears the site cache based on index files, available since version 1.0.13.'));
    }

    public function testRussianAndUkrainianAreToldApart(): void
    {
        $this->assertSame('ru', LanguageGuess::of('Очищает кэш сайта на основе индексных файлов. Доступно начиная с версии 1.0.13.'));
        $this->assertSame('uk', LanguageGuess::of('Очищає кеш сайту на основі індексних файлів. Доступно починаючи з версії 1.0.13, їх можна змінювати.'));
    }

    public function testCodeAndMarkupDoNotDecideTheLanguage(): void
    {
        $russianAroundCode = "Вызов функции очищает кэш всех документов на сайте.\n```php\n\$modx->clearCache('full'); // clear everything and rebuild the cache index\n```\n";
        $this->assertSame('ru', LanguageGuess::of($russianAroundCode));

        $englishAroundRussianCode = "Call this to clear the whole cache of the site.\n```php\n// очистить весь кэш сайта и перестроить индекс документов\n```\n";
        $this->assertSame('en', LanguageGuess::of($englishAroundRussianCode));
    }

    public function testTooLittleTextDefaultsToEnglish(): void
    {
        $this->assertSame('en', LanguageGuess::of('Да'));
        $this->assertSame('en', LanguageGuess::of(''));
    }

    public function testChunksCarryTheDetectedLanguageNotTheFolder(): void
    {
        $md = "# Кэш\n\nОчищает кэш сайта на основе индексных файлов, доступно с версии 1.0.13.\n\n# Cache\n\nClears the site cache based on index files, available since 1.0.13.\n";
        $chunks = (new MarkdownChunker())->chunkFile($md, 'a.md', 'evo-docs', 'en');

        $this->assertSame(['ru', 'en'], array_column(array_column($chunks, 'meta'), 'lang'));
        $this->assertSame('en', $chunks[0]['meta']['lang_folder']);
    }
}
