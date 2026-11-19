<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use App\Enum\FeedLanguage;
use App\Repository\SharedLinkRepository;
use App\Repository\SharedLinkResolutionRepository;
use App\Service\Content\LinkCard\Exception\SharedLinkNotReadBackException;
use App\Service\Content\LinkCard\Exception\UnhashableLinkException;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Url\Support\ShortLinkShape;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Language\PreviewLanguage;

/** The one writer of shared links: the first card of a URL fills its row, and a later card never rewrites it. */
final readonly class SharedLinkWriter
{
    public function __construct(
        private SharedLinkRepository $sharedLinks,
        private SharedLinkResolutionRepository $linkResolutions,
        private UrlNormalizer $urlNormalizer,
        private PreviewLanguage $previewLanguage,
        private NaiveUtcClock $clock,
    ) {
    }

    public function linkFor(LinkCardContent $card, ?FeedLanguage $statedLanguage): SharedLink
    {
        return $this->linksFor([$card], $statedLanguage === null ? [] : [$card->url => $statedLanguage])[$card->url];
    }

    /**
     * One claim for the batch, one insert of the URLs it lacks, and one read of those.
     *
     * @param list<LinkCardContent>     $cards
     * @param array<string, FeedLanguage> $statedByUrl the language a card's page states, by card URL
     *
     * @return array<string, SharedLink> by card URL
     */
    public function linksFor(array $cards, array $statedByUrl = []): array
    {
        $hashesByUrl = $this->hashesByUrl(array_map(static fn (LinkCardContent $card): string => $card->url, $cards));
        $links = $this->sharedLinks->claimByUrlHashes(array_values($hashesByUrl));
        $missing = [];
        foreach ($cards as $card) {
            $urlHash = $hashesByUrl[$card->url] ?? throw UnhashableLinkException::forUrl($card->url);
            if (!isset($links[$urlHash])) {
                $missing[$urlHash] ??= $this->newLink($urlHash, $card, $statedByUrl[$card->url] ?? null);
            }
        }
        if ($missing !== []) {
            $this->sharedLinks->insertMissing(array_values($missing));
            $links += $this->sharedLinks->byUrlHashes(array_keys($missing));
            $this->linkResolutions->queue(self::shortLinkIds($missing, $links));
        }

        $linksByUrl = [];
        foreach ($hashesByUrl as $url => $urlHash) {
            $linksByUrl[$url] = $links[$urlHash] ?? throw SharedLinkNotReadBackException::forUrl($url);
        }

        return $linksByUrl;
    }

    /**
     * @param list<LinkCardContent> $cards
     *
     * @return array<string, int> the shared link id by card URL
     */
    public function idsFor(array $cards): array
    {
        return array_map(static fn (SharedLink $link): int => $link->requireId(), $this->linksFor($cards));
    }

    /**
     * The stored links of these URLs, claimed for a reference about to be written; a URL without one is left out.
     *
     * @param list<string> $urls
     *
     * @return array<string, SharedLink> by URL
     */
    public function knownFor(array $urls): array
    {
        $hashesByUrl = $this->hashesByUrl($urls);
        $links = $this->sharedLinks->claimByUrlHashes(array_values($hashesByUrl));

        return array_filter(array_map(
            static fn (string $urlHash): ?SharedLink => $links[$urlHash] ?? null,
            $hashesByUrl,
        ));
    }

    public function existing(string $url): ?SharedLink
    {
        $urlHash = $this->hashesByUrl([$url])[$url] ?? null;

        return $urlHash === null ? null : ($this->sharedLinks->byUrlHashes([$urlHash])[$urlHash] ?? null);
    }

    private function newLink(string $urlHash, LinkCardContent $card, ?FeedLanguage $statedLanguage): NewSharedLinkModel
    {
        return new NewSharedLinkModel(
            $urlHash,
            $card,
            $this->previewLanguage->resolveLanguage($card, $statedLanguage),
            $this->clock->now(),
        );
    }

    /**
     * @param array<string, NewSharedLinkModel> $missing by url hash
     * @param array<string, SharedLink>         $links   by url hash
     *
     * @return list<int>
     */
    private static function shortLinkIds(array $missing, array $links): array
    {
        $ids = [];
        foreach ($missing as $urlHash => $link) {
            if (isset($links[$urlHash]) && ShortLinkShape::fits($link->preview->url)) {
                $ids[] = $links[$urlHash]->requireId();
            }
        }

        return $ids;
    }

    /**
     * Each URL hashed once; a URL that is no absolute web address is left out.
     *
     * @param list<string> $urls
     *
     * @return array<string, string> by URL
     */
    private function hashesByUrl(array $urls): array
    {
        $hashesByUrl = [];
        foreach ($urls as $url) {
            $urlHash = $hashesByUrl[$url] ?? $this->urlNormalizer->hash($url);
            if ($urlHash !== null) {
                $hashesByUrl[$url] = $urlHash;
            }
        }

        return $hashesByUrl;
    }
}
