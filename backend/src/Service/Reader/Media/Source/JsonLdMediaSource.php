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
 * walks the same script blocks but exposes only fully modelled Article cards
 * built from an already-decoded array via an injected PageUrls — nothing this
 * layer could reuse for a bare media URL, so this is its own walker.
 *
 * schema.org nests `contentUrl`/`embedUrl` under many shapes (VideoObject,
 * AudioObject, an Article's "video" property, …); rather than model each one,
 * every string value in the decoded tree is inspected under those two keys.
 * MediaUrlKind refuses anything that is not playable, which is what makes the
 * broad search safe — an ImageObject's contentUrl is just discarded.
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
            foreach ($this->urlsIn($script->textContent ?? '') as $urlWithPoster) {
                $candidate = $this->toCandidate(
                    $urlWithPoster['url'],
                    $urlWithPoster['poster'],
                    $blocks->before($script),
                );
                if ($candidate !== null) {
                    $found[$candidate->url] ??= $candidate;
                }
            }
        }

        return array_values($found);
    }

    /** @return list<array{url: string, poster: ?string}> */
    private function urlsIn(string $jsonLd): array
    {
        $decoded = json_decode($jsonLd, true);

        return \is_array($decoded) ? $this->collect($decoded) : [];
    }

    /**
     * schema.org nests a video's `thumbnailUrl` beside its `contentUrl` on the
     * same node, so the poster for a media URL is read from the node it was
     * found on, not searched for independently.
     *
     * @param array<mixed> $node
     *
     * @return list<array{url: string, poster: ?string}>
     */
    private function collect(array $node): array
    {
        $urls = [];
        foreach (self::URL_KEYS as $key) {
            if (isset($node[$key]) && \is_string($node[$key])) {
                $urls[] = ['url' => $node[$key], 'poster' => $this->thumbnailIn($node)];
            }
        }
        foreach ($node as $value) {
            if (\is_array($value)) {
                array_push($urls, ...$this->collect($value));
            }
        }

        return $urls;
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

    private function toCandidate(string $url, ?string $poster, ?string $precedingText): ?MediaCandidate
    {
        $resolved = $this->mediaUrlKind->resolve($url);
        if ($resolved === null) {
            return null;
        }
        if ($resolved->kind === MediaKind::Audio) {
            return new MediaCandidate(MediaKind::Audio, $resolved->url, null, null, $precedingText);
        }
        if ($resolved->kind === MediaKind::Video) {
            // D5: a poster-less video rots into a dead frame in the reader's TTL-less cache.
            return $poster === null || $poster === ''
                ? null
                : new MediaCandidate(MediaKind::Video, $resolved->url, $poster, null, $precedingText);
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
