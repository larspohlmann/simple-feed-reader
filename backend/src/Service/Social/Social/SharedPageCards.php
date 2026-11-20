<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Content\LinkCard\Support\StorableLinkCard;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Outbound\Fetch\Support\HostKey;
use Psr\Log\LoggerInterface;

/** The card a shared link goes out with: the page's own, else one titled with the link's host. */
final readonly class SharedPageCards
{
    public function __construct(
        private PageCardReaderInterface $pageCards,
        private LoggerInterface $logger,
    ) {
    }

    public function resolveCard(string $url): LinkCardContent
    {
        try {
            return $this->pageCards->read($url)->card ?? self::buildHostCard($url);
        } catch (PageFetchException $exception) {
            $this->logger->debug('The link card goes out without the page\'s own card.', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return self::buildHostCard($url);
        }
    }

    private static function buildHostCard(string $url): LinkCardContent
    {
        $host = HostKey::forUrl($url);
        $card = new LinkCardContent($url, $host, null, null, $host);

        return StorableLinkCard::fromCard($card) ?? $card;
    }
}
