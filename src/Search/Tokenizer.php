<?php

namespace Elcreator\aEvoAST\Search;

/**
 * Words out of identifiers and prose alike: "Core::clearCache" and "clear the cache"
 * both become [clear, cache]. English only, with a light suffix strip; this is for
 * matching a query to a signature, not for linguistics.
 */
final class Tokenizer
{
    private const STOP = [
        'a', 'an', 'the', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'is', 'are', 'be', 'it', 'its',
        'how', 'do', 'does', 'i', 'my', 'me', 'we', 'with', 'from', 'by', 'at', 'as', 'this', 'that',
        'can', 'what', 'which', 'use', 'using', 'new', 'if', 'when',
    ];

    /** @return string[] */
    public static function tokens(string $text): array
    {
        $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text);
        $text = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $text);
        preg_match_all('/[a-z0-9]+/', strtolower($text), $m);

        $out = [];
        foreach ($m[0] as $word) {
            if (strlen($word) < 2 || in_array($word, self::STOP, true)) {
                continue;
            }
            $out[] = self::stem($word);
        }

        return $out;
    }

    public static function stem(string $w): string
    {
        // Plural first, then -ing / -ed, so "settings" and "setting" end up in the same place.
        if (strlen($w) > 4 && str_ends_with($w, 'ies')) {
            $w = substr($w, 0, -3) . 'y';
        } elseif (strlen($w) > 5 && str_ends_with($w, 'sses')) {
            $w = substr($w, 0, -2);
        } elseif (strlen($w) > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss') && !str_ends_with($w, 'us')) {
            $w = substr($w, 0, -1);
        }

        if (strlen($w) > 6 && str_ends_with($w, 'ing')) {
            return substr($w, 0, -3);
        }
        if (strlen($w) > 4 && str_ends_with($w, 'ed')) {
            return substr($w, 0, -2);
        }

        return $w;
    }
}
