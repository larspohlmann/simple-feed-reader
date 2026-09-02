<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Sibling;

use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;

/**
 * One page's sibling search: the raw HTML is per-pass state, bound once here
 * and asked for every seed's siblings rather than threaded through every
 * private helper down to KeyedOccurrences and NearbyPoster.
 */
final readonly class SiblingSearch
{
    private const string ID_STEM = '/^[A-Za-z0-9_-]{6,}$/';
    private const string NUMBERED_SUFFIX = '/-\d+$/';
    private const int MAX_SIBLINGS = 5;

    public function __construct(private string $pageHtml)
    {
    }

    /** @return list<MediaCandidate> */
    public function siblingsOf(MediaCandidate $seed): array
    {
        $id = $seed->kind === MediaKind::Embed ? null : self::idOf($seed->url);
        if ($id === null) {
            return [];
        }

        $candidates = [];
        foreach (KeyedOccurrences::of($this->pageHtml, $id) as $occurrence) {
            foreach ($this->siblingsIn($occurrence, $id) as $sibling) {
                $candidate = $this->candidateFor($seed, $id, $sibling);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * Every other value under the seed's key whose own occurrence shares the seed's context.
     *
     * @return list<Sibling>
     */
    private function siblingsIn(KeyedOccurrence $seedOccurrence, string $seedId): array
    {
        $suffix = preg_match(self::NUMBERED_SUFFIX, $seedId) === 1 ? '-\d+' : '';
        $key = preg_quote($seedOccurrence->key, '/');
        $pattern = '/' . $key . '\\\\?"?\s*[:=]\s*\\\\?"([A-Za-z0-9_-]{6,}' . $suffix . ')\\\\?"/';
        preg_match_all($pattern, $this->pageHtml, $matches, \PREG_OFFSET_CAPTURE);

        $siblings = [];
        foreach ($matches[1] as [$id, $position]) {
            $occurrence = $id === $seedId ? null : KeyedOccurrences::at($this->pageHtml, $id, $position);
            if ($occurrence !== null && $occurrence->sharesContextWith($seedOccurrence)) {
                $siblings[$id] ??= new Sibling($id, $position);
            }
        }

        // More than a handful of siblings is a navigation or teaser list, not the article's media.
        return \count($siblings) > self::MAX_SIBLINGS ? [] : array_values($siblings);
    }

    private function candidateFor(MediaCandidate $seed, string $seedId, Sibling $sibling): ?MediaCandidate
    {
        if ($this->namedInsideAUrl($sibling->id)) {
            return null;
        }
        $poster = NearbyPoster::after($this->pageHtml, $sibling->position);

        return $poster === null
            ? null
            : new MediaCandidate($seed->kind, str_replace($seedId, $sibling->id, $seed->url), $poster);
    }

    private static function idOf(string $url): ?string
    {
        $path = parse_url($url, \PHP_URL_PATH);
        $stem = pathinfo(\is_string($path) ? $path : '', \PATHINFO_FILENAME);

        return preg_match(self::ID_STEM, $stem) === 1 ? $stem : null;
    }

    /** An id the page already names inside a URL belongs to the URL-based sources, which saw it and chose. */
    private function namedInsideAUrl(string $id): bool
    {
        $quoted = preg_quote($id, '#');

        return preg_match('#https?://[^\s"\'<>\\\\]*' . $quoted . '#', $this->pageHtml) === 1
            || preg_match('#/[^\s"\'<>\\\\]*' . $quoted . '\.[a-z0-9]{2,5}#', $this->pageHtml) === 1;
    }
}
