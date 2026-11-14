<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\Model\ResolvedMediaUrlModel;
use App\Service\Reader\Media\NarrationSignals;
use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * `<audio>` and `<video>` elements, for the element's own `poster`: AttributeMediaSource finds the same files but
 * knows only og:image, so two videos would share one still. Keep it above that source (PageMediaScannerWiringTest).
 */
#[AsTaggedItem(priority: 70)]
final readonly class SemanticMediaSource implements MediaCandidateSourceInterface
{
    public function __construct(
        private MediaUrlKind $urlKind,
        private PageFurniture $furniture,
        private NarrationSignals $narration,
    ) {
    }

    public function find(RawPageModel $page): array
    {
        $found = [];
        foreach ($page->document->querySelectorAll('audio, video') as $element) {
            if ($this->furniture->holds($element)) {
                continue;
            }
            $candidate = $this->candidateFor($element, $page->blocks->before($element));
            if ($candidate !== null) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    private function candidateFor(Element $element, ?string $precedingText): ?MediaCandidateModel
    {
        $resolved = $this->resolvedSourceOf($element);
        if ($resolved === null) {
            return null;
        }
        if (!$resolved->kind->isVideo()) {
            $narrated = $this->narration->narrates($resolved->url, $element);

            return new MediaCandidateModel($resolved->kind, $resolved->url, null, null, $precedingText, $narrated);
        }

        $poster = $element->getAttribute('poster') ?: null;

        return new MediaCandidateModel($resolved->kind, $resolved->url, $poster, null, $precedingText);
    }

    /** The element's own src or its first <source> whose kind fits the element: a <video> plays files and streams, an <audio> plays audio. */
    private function resolvedSourceOf(Element $element): ?ResolvedMediaUrlModel
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
