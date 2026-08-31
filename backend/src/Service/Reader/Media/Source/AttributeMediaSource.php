<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Host-agnostic: a publisher's player often hides its file URL in an ad-hoc
 * attribute (Deutschlandradio's `data-audio-src`, ARD's `data-v` rendition
 * list) rather than an `[src]` or JSON-LD block. This layer reads every
 * attribute of every element instead of naming either host.
 *
 * An attribute can hold a whole JSON blob, so a value is scanned for
 * URL-shaped substrings rather than trusted as one URL.
 */
#[AsTaggedItem(priority: 60)]
final readonly class AttributeMediaSource implements MediaCandidateSourceInterface
{
    private const string URL_PATTERN = '#https://[^"\'\s\\\\<>]+#i';

    private const string POSTER_PATTERN = '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i';

    public function __construct(
        private MediaUrlKind $kind,
        private MediaRelevance $relevance,
    ) {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $urlsByKind = $this->urlsByKind($this->urlsInAttributes($document));

        return $this->candidates($urlsByKind, $pageHtml, $pageUrl);
    }

    /** @return list<string> */
    private function urlsInAttributes(\Dom\HTMLDocument $document): array
    {
        $urls = [];
        foreach ($document->querySelectorAll('*') as $element) {
            foreach ($element->attributes as $attribute) {
                array_push($urls, ...$this->urlsInValue($attribute->value));
            }
        }

        return $urls;
    }

    /** @return list<string> */
    private function urlsInValue(string $attributeValue): array
    {
        // Values can be double-entity-encoded (a JSON string nested in a JSON
        // attribute); one more decode turns a stray "&quot;" back into a real
        // quote so the URL pattern stops at it instead of swallowing past it.
        $decoded = html_entity_decode($attributeValue, \ENT_QUOTES | \ENT_HTML5);
        preg_match_all(self::URL_PATTERN, $decoded, $matches);

        return $matches[0];
    }

    /**
     * @param list<string> $urls
     *
     * @return array<value-of<MediaKind>, list<string>>
     */
    private function urlsByKind(array $urls): array
    {
        $byKind = [];
        foreach ($urls as $url) {
            $resolved = $this->kind->resolve($url);
            if ($resolved !== null && $resolved->kind !== MediaKind::Embed) {
                $byKind[$resolved->kind->value][] = $resolved->url;
            }
        }

        return $byKind;
    }

    /**
     * @param array<value-of<MediaKind>, list<string>> $urlsByKind
     *
     * @return list<MediaCandidate>
     */
    private function candidates(array $urlsByKind, string $pageHtml, string $pageUrl): array
    {
        $candidates = [];
        foreach ($urlsByKind as $kindValue => $urls) {
            $candidate = $this->bestCandidate(MediaKind::from($kindValue), $urls, $pageHtml, $pageUrl);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @param list<string> $urls already resolved to their durable form */
    private function bestCandidate(MediaKind $kind, array $urls, string $pageHtml, string $pageUrl): ?MediaCandidate
    {
        $best = $this->relevance->rank($urls, $pageUrl)[0];
        if ($kind === MediaKind::Audio) {
            return new MediaCandidate(MediaKind::Audio, $best);
        }

        $poster = $this->ogImagePoster($pageHtml);

        // A publisher depublishes video on a schedule and the reader's cache
        // has no TTL; a poster-less video would rot into a dead frame instead
        // of a still with a failing play control, so it is dropped outright.
        return $poster === null ? null : new MediaCandidate(MediaKind::Video, $best, $poster);
    }

    private function ogImagePoster(string $pageHtml): ?string
    {
        if (preg_match(self::POSTER_PATTERN, $pageHtml, $matches) !== 1) {
            return null;
        }

        return preg_match('#^https://#i', $matches[1]) === 1 ? $matches[1] : null;
    }
}
