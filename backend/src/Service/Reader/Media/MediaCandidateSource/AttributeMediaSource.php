<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\Model\ScannedPageModel;
use App\Service\Reader\Media\NarrationSignals;
use App\Service\Reader\Media\PageFurniture;
use App\Service\Reader\Media\PlayerPoster;
use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Scans every attribute of every element, `href` included, for URL-shaped substrings: a player often hides its file
 * in an ad-hoc attribute or a JSON blob, not `[src]` or JSON-LD. The poster is og:image, else the still beside it.
 */
#[AsTaggedItem(priority: 60)]
final readonly class AttributeMediaSource implements MediaCandidateSourceInterface
{
    private const string URL_PATTERN = '#https://[^"\'\s\\\\<>]+#i';

    public function __construct(
        private MediaUrlKind $kind,
        private MediaRelevance $relevance,
        private PageFurniture $furniture,
        private NarrationSignals $narration,
        private PlayerPoster $playerPoster,
    ) {
    }

    public function find(RawPageModel $page): array
    {
        return $this->candidates($this->originsByKind($page->document), ScannedPageModel::from($page));
    }

    /**
     * Every media URL any attribute holds, with the first element that holds it: where the media first appears.
     *
     * @return array<value-of<MediaKind>, array<string, Element>> durable url => element
     */
    private function originsByKind(HTMLDocument $document): array
    {
        $byKind = [];
        foreach ($document->querySelectorAll('*') as $element) {
            if ($this->furniture->holds($element)) {
                continue;
            }
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
     * @return list<MediaCandidateModel>
     */
    private function candidates(array $originsByKind, ScannedPageModel $page): array
    {
        $candidates = [];
        foreach ($originsByKind as $kindValue => $origins) {
            $candidates[] = $this->bestCandidate(MediaKind::from($kindValue), $origins, $page);
        }

        return $candidates;
    }

    /** @param array<string, Element> $origins durable url => the element holding it */
    private function bestCandidate(MediaKind $kind, array $origins, ScannedPageModel $page): MediaCandidateModel
    {
        $best = $this->relevance->rank(array_keys($origins), $page->url)[0];
        $precedingText = $page->blocks->before($origins[$best]);
        if ($kind === MediaKind::Audio) {
            $narrated = $this->narration->narrates($best, $origins[$best]);

            return new MediaCandidateModel(MediaKind::Audio, $best, null, null, $precedingText, $narrated);
        }

        $poster = $page->posterUrl ?? $this->playerPoster->near($origins[$best]);

        return new MediaCandidateModel($kind, $best, $poster, null, $precedingText);
    }
}
