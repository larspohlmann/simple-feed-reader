<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageFurniture;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Host-agnostic: a publisher sometimes hangs the media file itself off an
 * ordinary link (NPR's "Listen" anchor) instead of a player attribute or an
 * embed. This layer reads every `a[href]` instead of naming the host.
 */
#[AsTaggedItem(priority: 50)]
final readonly class LinkedFileMediaSource implements MediaCandidateSourceInterface
{
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

    /** @return array<value-of<MediaKind>, array<string, Element>> durable url => the first anchor linking it */
    private function originsByKind(HTMLDocument $document): array
    {
        $byKind = [];
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            if (PageFurniture::holds($anchor)) {
                continue;
            }
            $href = $anchor->getAttribute('href');
            $resolved = $href === null ? null : $this->kind->resolve($href);
            if ($resolved !== null && $resolved->kind !== MediaKind::Embed) {
                $byKind[$resolved->kind->value][$resolved->url] ??= $anchor;
            }
        }

        return $byKind;
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

    /** @param array<string, Element> $origins durable url => the anchor linking it */
    private function bestCandidate(MediaKind $kind, array $origins, ScannedPage $page): ?MediaCandidate
    {
        // A linked video file has no poster to show alongside it, unlike the
        // attribute layer's og:image fallback, so it is dropped outright.
        if ($kind->isVideo()) {
            return null;
        }

        $best = $this->relevance->rank(array_keys($origins), $page->url)[0];

        return new MediaCandidate($kind, $best, null, null, $page->blocks->before($origins[$best]));
    }
}
