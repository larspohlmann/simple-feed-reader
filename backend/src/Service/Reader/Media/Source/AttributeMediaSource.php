<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use Dom\Element;
use Dom\HTMLDocument;
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

        return $this->candidates($this->originsByKind($document), ScannedPage::from($document, $pageUrl));
    }

    /**
     * Every media URL any attribute holds, with the element that holds it — the
     * first element wins when a URL repeats, so the anchor is where the media
     * first appears.
     *
     * @return array<value-of<MediaKind>, array<string, Element>> durable url => element
     */
    private function originsByKind(HTMLDocument $document): array
    {
        $byKind = [];
        foreach ($document->querySelectorAll('*') as $element) {
            foreach ($this->urlsOn($element) as $url) {
                $resolved = $this->kind->resolve($url);
                if ($resolved !== null && $resolved->kind !== MediaKind::Embed) {
                    $byKind[$resolved->kind->value][$resolved->url] ??= $element;
                }
            }
        }

        return $byKind;
    }

    /** @return list<string> */
    private function urlsOn(Element $element): array
    {
        $urls = [];
        foreach ($element->attributes as $attribute) {
            array_push($urls, ...$this->urlsInValue($attribute->value));
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
     * @param array<value-of<MediaKind>, array<string, Element>> $originsByKind
     *
     * @return list<MediaCandidate>
     */
    private function candidates(array $originsByKind, ScannedPage $page): array
    {
        $candidates = [];
        foreach ($originsByKind as $kindValue => $origins) {
            $candidate = $this->bestCandidate(MediaKind::from($kindValue), $origins, $page);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @param array<string, Element> $origins durable url => the element holding it */
    private function bestCandidate(MediaKind $kind, array $origins, ScannedPage $page): ?MediaCandidate
    {
        $best = $this->relevance->rank(array_keys($origins), $page->url)[0];
        $precedingText = $page->blocks->before($origins[$best]);
        if ($kind === MediaKind::Audio) {
            return new MediaCandidate(MediaKind::Audio, $best, null, null, $precedingText);
        }

        // A publisher depublishes video on a schedule and the reader's cache
        // has no TTL; a poster-less video would rot into a dead frame instead
        // of a still with a failing play control, so it is dropped outright.
        return $page->posterUrl === null
            ? null
            : new MediaCandidate(MediaKind::Video, $best, $page->posterUrl, null, $precedingText);
    }
}
