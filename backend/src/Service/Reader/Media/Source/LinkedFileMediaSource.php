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

        return $this->candidates($this->hrefsByKind($document), $pageUrl);
    }

    /** @return array<value-of<MediaKind>, list<string>> durable urls, keyed by kind */
    private function hrefsByKind(\Dom\HTMLDocument $document): array
    {
        $byKind = [];
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $href = $anchor->getAttribute('href');
            $resolved = $href === null ? null : $this->kind->resolve($href);
            if ($resolved !== null && $resolved->kind !== MediaKind::Embed) {
                $byKind[$resolved->kind->value][] = $resolved->url;
            }
        }

        return $byKind;
    }

    /**
     * @param array<value-of<MediaKind>, list<string>> $hrefsByKind
     *
     * @return list<MediaCandidate>
     */
    private function candidates(array $hrefsByKind, string $pageUrl): array
    {
        $candidates = [];
        foreach ($hrefsByKind as $kindValue => $hrefs) {
            $candidate = $this->bestCandidate(MediaKind::from($kindValue), $hrefs, $pageUrl);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @param list<string> $hrefs already resolved to their durable form */
    private function bestCandidate(MediaKind $kind, array $hrefs, string $pageUrl): ?MediaCandidate
    {
        // A linked video file has no poster to show alongside it, unlike the
        // attribute layer's og:image fallback, so it is dropped outright.
        if ($kind === MediaKind::Video) {
            return null;
        }

        $best = $this->relevance->rank($hrefs, $pageUrl)[0];

        return new MediaCandidate($kind, $best);
    }
}
