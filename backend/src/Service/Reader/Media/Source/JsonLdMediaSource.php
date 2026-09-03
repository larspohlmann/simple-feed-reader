<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageFurniture;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\PageTextBlocks;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A publisher's own schema.org markup. `Service/Scraper/JsonLdArticles.php`
 * walks the same blocks but exposes only modelled Article cards, nothing this
 * layer could reuse for a bare media URL — so this is its own walker.
 *
 * schema.org nests `contentUrl`/`embedUrl` under many shapes (VideoObject,
 * AudioObject, an Article's "video" property, …), so every string value in
 * the decoded tree is inspected under those two keys rather than modelling
 * each shape. MediaUrlKind refuses anything not playable, which makes the
 * broad search safe — an ImageObject's contentUrl is simply discarded. A node
 * yields its first playable URL in URL_KEYS order: one asset, one candidate.
 */
#[AsTaggedItem(priority: 100)]
final readonly class JsonLdMediaSource implements MediaCandidateSourceInterface
{
    private const array URL_KEYS = ['contentUrl', 'embedUrl'];

    public function __construct(
        private MediaUrlKind $mediaUrlKind,
        private EmbedProviders $embedProviders,
    ) {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $blocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $script) {
            if (PageFurniture::holds($script)) {
                continue;
            }
            foreach ($this->declarationsIn($script->textContent ?? '') as $declaration) {
                $candidate = $this->firstPlayable($declaration, $blocks->before($script));
                if ($candidate !== null) {
                    $found[$candidate->url] ??= $candidate;
                }
            }
        }

        return array_values($found);
    }

    /** @return list<array{urls: list<string>, poster: ?string}> */
    private function declarationsIn(string $jsonLd): array
    {
        $decoded = json_decode($jsonLd, true);

        return \is_array($decoded) ? $this->collect($decoded) : [];
    }

    /**
     * One node is one asset: its URL keys are gathered together, with the poster
     * schema.org places beside them (`thumbnailUrl` on the same node).
     *
     * @param array<mixed> $node
     *
     * @return list<array{urls: list<string>, poster: ?string}>
     */
    private function collect(array $node): array
    {
        $urls = [];
        foreach (self::URL_KEYS as $key) {
            if (isset($node[$key]) && \is_string($node[$key])) {
                $urls[] = $node[$key];
            }
        }
        $declarations = $urls === [] ? [] : [['urls' => $urls, 'poster' => $this->thumbnailIn($node)]];
        foreach ($node as $value) {
            if (\is_array($value)) {
                array_push($declarations, ...$this->collect($value));
            }
        }

        return $declarations;
    }

    /** @param array<mixed> $node */
    private function thumbnailIn(array $node): ?string
    {
        $thumbnail = $node['thumbnailUrl'] ?? null;
        if (\is_string($thumbnail)) {
            return $thumbnail;
        }

        return \is_array($thumbnail) && \is_string($thumbnail[0] ?? null) ? $thumbnail[0] : null;
    }

    /**
     * The file beats the player page of the same asset; the page is the fallback for a refused file.
     *
     * @param array{urls: list<string>, poster: ?string} $declaration
     */
    private function firstPlayable(array $declaration, ?string $precedingText): ?MediaCandidate
    {
        foreach ($declaration['urls'] as $url) {
            $candidate = $this->toCandidate($url, $declaration['poster'], $precedingText);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function toCandidate(string $url, ?string $poster, ?string $precedingText): ?MediaCandidate
    {
        $resolved = $this->mediaUrlKind->resolve($url);
        if ($resolved === null) {
            return null;
        }
        if ($resolved->kind === MediaKind::Audio) {
            return new MediaCandidate(MediaKind::Audio, $resolved->url, null, null, $precedingText);
        }
        if ($resolved->kind->isVideo()) {
            // D5: a poster-less video rots into a dead frame in the reader's TTL-less cache.
            return $poster === null || $poster === ''
                ? null
                : new MediaCandidate($resolved->kind, $resolved->url, $poster, null, $precedingText);
        }

        $target = $this->embedProviders->resolve($url);
        if ($target === null) {
            return null;
        }

        return new MediaCandidate(
            MediaKind::Embed,
            $target->url,
            $target->posterUrl ?? $poster,
            $target->label,
            $precedingText,
        );
    }
}
