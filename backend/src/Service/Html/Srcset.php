<?php

declare(strict_types=1);

namespace App\Service\Html;

/**
 * Reads a `srcset` attribute: a list of candidates, each a URL optionally
 * followed by a width or density descriptor.
 *
 * A comma separates candidates, but a comma is also a legal URL character, so
 * the list cannot be split on every comma. Substack serves Cloudinary transform
 * URLs that spell their options `$s_!-_9x!,w_1456,c_limit,f_auto` (#706), and
 * splitting those shreds each candidate into fragments. The HTML parsing rules
 * read a URL as a run of non-space characters and treat only the comma that
 * ends such a run as the separator, which is what this reader does.
 */
final class Srcset
{
    /** The only descriptor that states a pixel width; `2x` states a density. */
    private const string WIDTH_DESCRIPTOR = '/^(\d+)w$/';

    /** The first candidate URL of a srcset list, or null when it yields none. */
    public static function firstUrl(?string $srcset): ?string
    {
        return self::candidates($srcset)[0]->url ?? null;
    }

    /**
     * The candidate with the greatest declared width, so a reader that shows one
     * rendition picks the sharpest. A list with no width descriptors falls back
     * to its first candidate, matching how a browser treats a bare srcset.
     */
    public static function widest(?string $srcset): ?ImageRendition
    {
        $widest = null;
        foreach (self::candidates($srcset) as $candidate) {
            if ($widest === null || self::outmeasures($candidate, $widest)) {
                $widest = $candidate;
            }
        }

        return $widest;
    }

    /**
     * The renditions the list declares, in source order.
     *
     * @return list<ImageRendition>
     */
    private static function candidates(?string $srcset): array
    {
        if ($srcset === null) {
            return [];
        }

        return array_map(self::renditionFrom(...), self::candidateTokens($srcset));
    }

    /**
     * The space-separated tokens of each candidate. A token closes its candidate
     * when it ends in a comma; commas anywhere else stay inside the token, which
     * is what keeps a transform URL whole.
     *
     * @return list<non-empty-list<string>>
     */
    private static function candidateTokens(string $srcset): array
    {
        $candidates = [];
        $current = [];

        foreach (self::spaceSeparated($srcset) as $token) {
            $value = rtrim($token, ',');
            if ($value !== '') {
                $current[] = $value;
            }
            if ($value === $token || $current === []) {
                continue;
            }
            $candidates[] = $current;
            $current = [];
        }

        if ($current !== []) {
            $candidates[] = $current;
        }

        return $candidates;
    }

    /**
     * The list's tokens. Dropping the empty pieces also absorbs the whitespace
     * around the list, so a blank srcset yields no tokens at all.
     *
     * @return list<string>
     */
    private static function spaceSeparated(string $srcset): array
    {
        $tokens = preg_split('/\s+/', $srcset, -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : $tokens;
    }

    /**
     * A candidate's URL with the width its descriptor declares.
     *
     * @param non-empty-list<string> $tokens
     */
    private static function renditionFrom(array $tokens): ImageRendition
    {
        return new ImageRendition($tokens[0], self::declaredWidth($tokens[1] ?? null));
    }

    /** The pixel width a descriptor states, or null when it states none. */
    private static function declaredWidth(?string $descriptor): ?int
    {
        if ($descriptor === null || preg_match(self::WIDTH_DESCRIPTOR, $descriptor, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
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
