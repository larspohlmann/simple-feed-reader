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

    /**
     * The candidate with the greatest declared width, so a reader that shows one
     * rendition picks the sharpest. A list with no width descriptors falls back
     * to its first candidate, matching how a browser treats a bare srcset.
     */
    public static function widest(?string $srcset): ?ImageRendition
    {
        if ($srcset === null) {
            return null;
        }

        $widest = null;
        foreach (explode(',', $srcset) as $candidate) {
            $rendition = self::parseCandidate($candidate);
            if ($rendition === null) {
                continue;
            }
            if ($widest === null || self::outmeasures($rendition, $widest)) {
                $widest = $rendition;
            }
        }

        return $widest;
    }

    /** A single `url 768w` candidate, or null when it carries no URL. */
    private static function parseCandidate(string $candidate): ?ImageRendition
    {
        $parts = preg_split('/\s+/', $candidate, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === []) {
            return null;
        }

        $width = isset($parts[1]) && preg_match('/^(\d+)w$/', $parts[1], $matches) === 1
            ? (int) $matches[1]
            : null;

        return new ImageRendition($parts[0], $width);
    }

    /**
     * True when the candidate is wider than the incumbent. A candidate that
     * declares no width never displaces one that does; the first candidate wins
     * only until a measured one appears.
     */
    private static function outmeasures(ImageRendition $candidate, ImageRendition $incumbent): bool
    {
        if ($candidate->width === null) {
            return false;
        }

        return $incumbent->width === null || $candidate->width > $incumbent->width;
    }
}
