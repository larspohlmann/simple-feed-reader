<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * Reads a `srcset` attribute. A srcset is a comma-separated list of candidates,
 * each a URL optionally followed by a width or density descriptor; the first
 * candidate's URL is the one every reader/scraper caller wants.
 */
final class Srcset
{
    /** The first candidate URL of a srcset list, or null when it yields none. */
    public static function firstUrl(?string $srcset): ?string
    {
        if ($srcset === null || preg_match('/\S+/', explode(',', $srcset)[0], $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
