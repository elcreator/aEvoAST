<?php

namespace Elcreator\aEvoAST\Parsers;

/**
 * Which language is this text written in? Only what the Evolution CMS docs need: English,
 * Russian or Ukrainian, told apart by script. The docs repository has an en/ folder, but a
 * large part of its pages are still Russian, so the folder name cannot be trusted.
 */
final class LanguageGuess
{
    /** Letters only the Ukrainian alphabet has (Russian never uses them). */
    private const UK_ONLY = '/[іїєґІЇЄҐ]/u';

    /** @return string 'en' | 'ru' | 'uk' */
    public static function of(string $markdown): string
    {
        // Code and markup are English whatever the page says.
        $text = preg_replace('/```.*?```|~~~.*?~~~/s', ' ', $markdown);
        $text = preg_replace('/`[^`]*`|<[^>]+>|\]\([^)]*\)|https?:\/\/\S+/', ' ', $text);

        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $text);
        $latin = preg_match_all('/[A-Za-z]/', $text);
        $letters = $cyrillic + $latin;

        if ($letters < 20 || $cyrillic / $letters < 0.3) {
            return 'en';
        }

        return preg_match_all(self::UK_ONLY, $text) >= 2 ? 'uk' : 'ru';
    }
}
