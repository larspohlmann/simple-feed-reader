<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Outbound\Fetch\Support\HostKey;
use Psr\Log\LoggerInterface;

/**
 * Reads a shared link's card at its tracking-free URL, and keeps the URL as posted when the posted one shows another
 * page: a stripped key then named the page, and the log says which.
 */
final readonly class PostedUrlCheck
{
    public function __construct(
        private PageCardReaderInterface $pageCards,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws PageFetchException when neither URL can be read */
    public function cardAt(string $url, string $postedUrl): PageCardModel
    {
        try {
            $cleaned = $this->pageCards->read($url);
        } catch (PageFetchException $exception) {
            return $this->kept($url, $postedUrl, $this->postedOr($postedUrl, $exception));
        }
        if ($cleaned->identity === null) {
            return $cleaned;
        }
        try {
            $posted = $this->pageCards->read($postedUrl);
        } catch (PageFetchException) {
            return $cleaned;
        }

        return $posted->identity === null || $cleaned->identity->isSamePageAs($posted->identity)
            ? $cleaned
            : $this->kept($url, $postedUrl, $posted);
    }

    /** @throws PageFetchException */
    private function postedOr(string $postedUrl, PageFetchException $cleanedFailure): PageCardModel
    {
        try {
            return $this->pageCards->read($postedUrl);
        } catch (PageFetchException) {
            throw $cleanedFailure;
        }
    }

    private function kept(string $url, string $postedUrl, PageCardModel $posted): PageCardModel
    {
        $this->logger->info(
            'A link card keeps its posted URL: stripping its tracking keys showed another page',
            ['host' => HostKey::forUrl($postedUrl), 'strippedKeys' => self::strippedKeys($postedUrl, $url)],
        );
        $card = $posted->card;
        if ($card === null) {
            return $posted;
        }

        return new PageCardModel(
            new LinkCardContent($postedUrl, $card->title, $card->description, $card->imageUrl, $card->siteName),
            $posted->medium,
            $posted->identity,
        );
    }

    /** @return list<string> */
    private static function strippedKeys(string $postedUrl, string $url): array
    {
        return array_values(array_diff(self::keysIn($postedUrl), self::keysIn($url)));
    }

    /** @return list<string> */
    private static function keysIn(string $url): array
    {
        $pairs = (string) parse_url($url, \PHP_URL_QUERY) . '&' . (string) parse_url($url, \PHP_URL_FRAGMENT);

        return array_values(array_map(
            static fn (string $pair): string => explode('=', $pair)[0],
            array_filter(explode('&', $pairs), static fn (string $pair): bool => $pair !== ''),
        ));
    }
}
