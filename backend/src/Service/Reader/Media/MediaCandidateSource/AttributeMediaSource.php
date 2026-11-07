<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\AudioClipFacts;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\Model\ResolvedMediaUrlModel;
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
        private AudioClipFacts $clipFacts,
    ) {
    }

    public function find(RawPageModel $page): array
    {
        return $this->candidates($this->holdersByKind($page->document), ScannedPageModel::from($page));
    }

    /**
     * Every media URL any attribute holds, with the elements that hold it in document order: the first is where the
     * media first appears.
     *
     * @return array<value-of<MediaKind>, array<string, list<Element>>> durable url => holders
     */
    private function holdersByKind(HTMLDocument $document): array
    {
        $byKind = [];
        foreach ($document->querySelectorAll('*') as $element) {
            if ($this->furniture->holds($element)) {
                continue;
            }
            foreach ($this->mediaUrlsOn($element) as $resolved) {
                $byKind[$resolved->kind->value][$resolved->url][] = $element;
            }
        }

        return $byKind;
    }

    /** @return array<string, ResolvedMediaUrlModel> durable url => its resolution, once per element */
    private function mediaUrlsOn(Element $element): array
    {
        $resolutions = [];
        foreach ($this->urlsOn($element) as $url) {
            $resolved = $this->kind->resolve($url);
            if ($resolved !== null && $resolved->kind !== MediaKind::Embed) {
                $resolutions[$resolved->url] = $resolved;
            }
        }

        return $resolutions;
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
     * @param array<value-of<MediaKind>, array<string, list<Element>>> $holdersByKind
     *
     * @return list<MediaCandidateModel>
     */
    private function candidates(array $holdersByKind, ScannedPageModel $page): array
    {
        $candidates = [];
        foreach ($holdersByKind as $kindValue => $holdersByUrl) {
            $candidates[] = $this->bestCandidate(MediaKind::from($kindValue), $holdersByUrl, $page);
        }

        return $candidates;
    }

    /** @param array<string, list<Element>> $holdersByUrl durable url => the elements holding it */
    private function bestCandidate(MediaKind $kind, array $holdersByUrl, ScannedPageModel $page): MediaCandidateModel
    {
        $best = $this->relevance->rank(array_keys($holdersByUrl), $page->url)[0];
        $holders = $holdersByUrl[$best];
        $precedingText = $page->blocks->before($holders[0]);
        if ($kind === MediaKind::Audio) {
            return new MediaCandidateModel(
                MediaKind::Audio,
                $best,
                label: $this->clipFacts->titleOf($holders),
                precedingText: $precedingText,
                narrated: $this->narration->narrates($best, $holders[0]),
                durationSeconds: $this->clipFacts->durationOf($holders),
            );
        }

        $poster = $page->posterUrl ?? $this->playerPoster->near($holders[0]);

        return new MediaCandidateModel($kind, $best, $poster, null, $precedingText);
    }
}
