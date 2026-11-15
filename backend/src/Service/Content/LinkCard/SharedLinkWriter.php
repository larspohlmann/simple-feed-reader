<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\LinkCardContent;
use App\Entity\SharedLink;
use App\Enum\FeedLanguage;
use App\Repository\SharedLinkRepository;
use App\Service\Content\LinkCard\Exception\UnhashableLinkException;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Language\PreviewLanguage;

/** The one writer of shared links: the first card of a URL fills its row, and a later card never rewrites it. */
final readonly class SharedLinkWriter
{
    public function __construct(
        private SharedLinkRepository $sharedLinks,
        private UrlNormalizer $urlNormalizer,
        private PreviewLanguage $previewLanguage,
        private NaiveUtcClock $clock,
    ) {
    }

    public function linkFor(LinkCardContent $card, ?FeedLanguage $statedLanguage): SharedLink
    {
        $urlHash = $this->hashOf($card->url);
        $stored = $this->sharedLinks->findOneByUrlHash($urlHash);
        if ($stored !== null) {
            return $stored;
        }
        $this->sharedLinks->insertMissing([$this->newLink($urlHash, $card, $statedLanguage)]);

        return $this->sharedLinks->findOneByUrlHash($urlHash) ?? throw UnhashableLinkException::forUrl($card->url);
    }

    /**
     * @param list<LinkCardContent> $cards
     *
     * @return array<string, int> the shared link id by card URL
     */
    public function idsFor(array $cards): array
    {
        $cardsByHash = [];
        foreach ($cards as $card) {
            $cardsByHash[$this->hashOf($card->url)] ??= $card;
        }
        $ids = $this->sharedLinks->idsByUrlHash(array_keys($cardsByHash));
        $missing = array_diff_key($cardsByHash, $ids);
        if ($missing !== []) {
            $newLinks = [];
            foreach ($missing as $urlHash => $card) {
                $newLinks[] = $this->newLink($urlHash, $card, null);
            }
            $this->sharedLinks->insertMissing($newLinks);
            $ids += $this->sharedLinks->idsByUrlHash(array_keys($missing));
        }

        $idsByUrl = [];
        foreach ($cards as $card) {
            $idsByUrl[$card->url] = $ids[$this->hashOf($card->url)];
        }

        return $idsByUrl;
    }

    private function newLink(string $urlHash, LinkCardContent $card, ?FeedLanguage $statedLanguage): NewSharedLinkModel
    {
        return new NewSharedLinkModel(
            $urlHash,
            $card,
            $this->previewLanguage->of($card, $statedLanguage),
            $this->clock->now(),
        );
    }

    private function hashOf(string $url): string
    {
        return $this->urlNormalizer->hash($url) ?? throw UnhashableLinkException::forUrl($url);
    }
}
