<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * `<audio>` and `<video>` elements, read at face value. The case that needs no
 * cleverness at all — no host adapter covers it because none has to.
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

        $found = [];
        foreach ($document->querySelectorAll('audio, video') as $element) {
            $candidate = $this->candidateFor($element);
            if ($candidate !== null) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    private function candidateFor(\Dom\Element $element): ?MediaCandidate
    {
        $kind = $element->nodeName === 'VIDEO' ? MediaKind::Video : MediaKind::Audio;
        $url = $this->usableUrl($element, $kind);
        if ($url === null) {
            return null;
        }

        if ($kind !== MediaKind::Video) {
            return new MediaCandidate($kind, $url);
        }

        // A video with no poster (absent or empty) rots into a dead frame in a cache with no TTL.
        $poster = $element->getAttribute('poster');

        return $poster === null || $poster === '' ? null : new MediaCandidate($kind, $url, $poster);
    }

    private function usableUrl(\Dom\Element $element, MediaKind $expectedKind): ?string
    {
        $src = $element->getAttribute('src');
        $resolved = $src === null ? null : $this->urlKind->resolve($src);
        if ($resolved !== null && $resolved->kind === $expectedKind) {
            return $resolved->url;
        }

        foreach ($element->querySelectorAll('source') as $source) {
            $sourceUrl = $source->getAttribute('src');
            $resolvedSource = $sourceUrl === null ? null : $this->urlKind->resolve($sourceUrl);
            if ($resolvedSource !== null && $resolvedSource->kind === $expectedKind) {
                return $resolvedSource->url;
            }
        }

        return null;
    }
}
