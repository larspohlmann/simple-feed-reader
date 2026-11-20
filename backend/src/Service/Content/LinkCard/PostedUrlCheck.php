<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Outbound\Fetch\Support\HostKey;
use Psr\Log\LoggerInterface;

/**
 * Reads a shared link's card at its tracking-free URL, and keeps the URL as posted when the posted one shows another
 * page: a stripped key then named the page, and the log says which.
 */
final readonly class PostedUrlCheck
{
    private const string ANOTHER_PAGE = 'A link card keeps its posted URL: its cleaned form showed another page';
    private const string CLEANED_FAILED = 'A link card keeps its posted URL: the cleaned URL failed';

    public function __construct(
        private PageCardReaderInterface $pageCards,
        private LoggerInterface $logger,
    ) {
    }

    /** @throws PageFetchException when neither URL can be read */
    public function resolveCard(string $url, string $postedUrl): PageCardModel
    {
        try {
            $cleaned = $this->pageCards->read($url);
        } catch (PageFetchException $exception) {
            return $this->kept($postedUrl, $this->tryRead($postedUrl) ?? throw $exception, self::CLEANED_FAILED);
        }
        if ($cleaned->identity === null) {
            return $cleaned;
        }
        $posted = $this->tryRead($postedUrl);
        if ($posted?->identity === null || $cleaned->identity->isSamePageAs($posted->identity)) {
            return $cleaned;
        }

        return $this->kept($postedUrl, $posted, self::ANOTHER_PAGE);
    }

    private function tryRead(string $url): ?PageCardModel
    {
        try {
            return $this->pageCards->read($url);
        } catch (PageFetchException) {
            return null;
        }
    }

    private function kept(string $postedUrl, PageCardModel $posted, string $reason): PageCardModel
    {
        $this->logger->info($reason, [
            'host' => HostKey::forUrl($postedUrl),
            'strippedKeys' => TrackingFreeUrl::read($postedUrl)->strippedKeys(),
        ]);

        return $posted->card === null
            ? $posted
            : new PageCardModel($posted->card->withUrl($postedUrl), $posted->medium, $posted->identity);
    }
}
