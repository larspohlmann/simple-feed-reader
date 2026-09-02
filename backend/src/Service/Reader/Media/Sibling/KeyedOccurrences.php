<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

/**
 * Where a page names an id as a keyed value — `"key":"id"` in a script payload
 * (escaped quotes allowed) or `key="id"` on an element. An id inside a URL path
 * has no key and is not an occurrence.
 */
final readonly class KeyedOccurrences
{
    private const string KEY_BEFORE_VALUE = '/([A-Za-z_][A-Za-z0-9_-]*)\\\\?"?\s*[:=]\s*\\\\?"$/';
    private const string ANY_KEY = '/\\\\?"?([A-Za-z_][A-Za-z0-9_-]*)\\\\?"?\s*[:=]\s*/';
    private const int WINDOW = 200;

    /** @return list<KeyedOccurrence> */
    public static function of(string $html, string $id): array
    {
        $found = [];
        $offset = 0;
        while (($position = strpos($html, $id, $offset)) !== false) {
            $offset = $position + 1;
            $occurrence = self::at($html, $id, $position);
            if ($occurrence !== null) {
                $found[] = $occurrence;
            }
        }

        return $found;
    }

    public static function at(string $html, string $id, int $position): ?KeyedOccurrence
    {
        $before = substr($html, max(0, $position - self::WINDOW), min(self::WINDOW, $position));
        if (preg_match(self::KEY_BEFORE_VALUE, $before, $key) !== 1) {
            return null;
        }
        $after = substr($html, $position + \strlen($id), self::WINDOW);

        return new KeyedOccurrence(
            $key[1],
            self::lastKeyIn(substr($before, 0, -\strlen($key[0]))),
            self::firstKeyIn($after),
            $position,
        );
    }

    private static function lastKeyIn(string $text): string
    {
        preg_match_all(self::ANY_KEY, $text, $matches);
        $last = end($matches[1]);

        return $last === false ? '' : $last;
    }

    private static function firstKeyIn(string $text): string
    {
        return preg_match(self::ANY_KEY, $text, $matches) === 1 ? $matches[1] : '';
    }
}
