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

    /** A density descriptor; a candidate without one is 1x, as the HTML spec reads it. */
    private const string DENSITY_DESCRIPTOR = '/^(\d+(?:\.\d+)?)x$/';

    /** The first candidate URL of a srcset list, or null when it yields none. */
    public static function firstUrl(?string $srcset): ?string
    {
        return self::candidates($srcset)[0]->url ?? null;
    }

    /**
     * The candidate with the greatest declared width, so a reader that shows one
     * rendition picks the sharpest. A list with no width descriptors ranks by
     * density instead, the densest candidate being the largest file; a bare
     * list is all 1x and keeps its first candidate, as a browser would.
     */
    public static function widest(?string $srcset): ?ImageRendition
    {
        $widest = null;
        foreach (self::candidates($srcset) as $candidate) {
            if ($widest === null || $candidate->outmeasures($widest)) {
                $widest = $candidate;
            }
        }

        return $widest?->rendition();
    }

    /**
     * The candidates the list declares, in source order.
     *
     * @return list<SrcsetCandidate>
     */
    private static function candidates(?string $srcset): array
    {
        if ($srcset === null) {
            return [];
        }

        return array_map(self::candidateFrom(...), self::candidateTokens($srcset));
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
     * A candidate's URL with the width or density its descriptor declares.
     *
     * @param non-empty-list<string> $tokens
     */
    private static function candidateFrom(array $tokens): SrcsetCandidate
    {
        $descriptor = $tokens[1] ?? null;

        return new SrcsetCandidate($tokens[0], self::declaredWidth($descriptor), self::declaredDensity($descriptor));
    }

    /** The pixel width a descriptor states, or null when it states none. */
    private static function declaredWidth(?string $descriptor): ?int
    {
        if ($descriptor === null || preg_match(self::WIDTH_DESCRIPTOR, $descriptor, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /** The density a descriptor states; 1x when it states none. */
    private static function declaredDensity(?string $descriptor): float
    {
        if ($descriptor === null || preg_match(self::DENSITY_DESCRIPTOR, $descriptor, $matches) !== 1) {
            return 1.0;
        }

        return (float) $matches[1];
    }
}
