<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\LinkCardContent;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Reader\Exception\PageFetchException;
use Psr\Log\LoggerInterface;

/** The card a shared link goes out with: the page's own, else one titled with the link's host. */
final readonly class SharedPageCards
{
    public function __construct(
        private PageCardReaderInterface $pageCards,
        private LoggerInterface $logger,
    ) {
    }

    public function cardOf(string $url): LinkCardContent
    {
        try {
            return $this->pageCards->read($url)->card ?? self::hostCardOf($url);
        } catch (PageFetchException $exception) {
            $this->logger->debug('The link card goes out without the page\'s own card.', [
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return self::hostCardOf($url);
        }
    }

    private static function hostCardOf(string $url): LinkCardContent
    {
        $host = parse_url($url, \PHP_URL_HOST);
        $title = \is_string($host) && '' !== $host ? $host : $url;

        return new LinkCardContent($url, $title, null, null, $title);
    }
}
