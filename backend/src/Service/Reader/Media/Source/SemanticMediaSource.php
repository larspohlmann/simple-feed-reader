<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\PageFurniture;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\PageTextBlocks;
use App\Service\Reader\Media\ResolvedMediaUrl;
use Dom\Element;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * `<audio>` and `<video>` elements, read at face value. This layer is not here
 * to FIND the file — AttributeMediaSource reads `src` too and would find every
 * one of these. It is here for the element's own `poster`, which only element
 * context can supply: the attribute scan knows the page's og:image alone, so
 * on a two-video article it would give both videos the same still (#756).
 * That is why it must stay ABOVE AttributeMediaSource; the wiring test pins it.
 */
#[AsTaggedItem(priority: 70)]
final readonly class SemanticMediaSource implements MediaCandidateSourceInterface
{
    public function __construct(private MediaUrlKind $urlKind)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $blocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($document->querySelectorAll('audio, video') as $element) {
            if (PageFurniture::holds($element)) {
                continue;
            }
            $candidate = $this->candidateFor($element, $blocks->before($element));
            if ($candidate !== null) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    private function candidateFor(Element $element, ?string $precedingText): ?MediaCandidate
    {
        $resolved = $this->resolvedSourceOf($element);
        if ($resolved === null) {
            return null;
        }
        if (!$resolved->kind->isVideo()) {
            return new MediaCandidate($resolved->kind, $resolved->url, null, null, $precedingText);
        }

        // A video with no poster (absent or empty) rots into a dead frame in a cache with no TTL.
        $poster = $element->getAttribute('poster');

        return $poster === null || $poster === ''
            ? null
            : new MediaCandidate($resolved->kind, $resolved->url, $poster, null, $precedingText);
    }

    /** The element's own src or its first <source> whose kind fits the element: a <video> plays files and streams, an <audio> plays audio. */
    private function resolvedSourceOf(Element $element): ?ResolvedMediaUrl
    {
        $urls = [$element->getAttribute('src')];
        foreach ($element->querySelectorAll('source') as $source) {
            $urls[] = $source->getAttribute('src');
        }
        foreach ($urls as $url) {
            $resolved = $url === null ? null : $this->urlKind->resolve($url);
            if ($resolved !== null && $resolved->kind->isVideo() === ($element->nodeName === 'VIDEO')) {
                return $resolved;
            }
        }

        return null;
    }
}
