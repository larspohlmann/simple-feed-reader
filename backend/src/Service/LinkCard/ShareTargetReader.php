<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Service\LinkCard\Exception\QuotedPostUnavailableException;
use App\Service\LinkCard\Model\EntryCardsModel;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Model\QuotedTargetModel;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\LinkCard\QuotedPostSource\QuotedPostSourceInterface;
use App\Service\LinkCard\Support\QuoteLine;
use App\Service\Reader\Exception\PageFetchException;
use Psr\Log\LoggerInterface;

/** Reads what a share's uncarded targets show: the post its "RE:" line quotes, and a page card for every other link. */
final readonly class ShareTargetReader
{
    public function __construct(
        private PageCardReaderInterface $pageCards,
        private QuotedPostSourceInterface $quotedPosts,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<LinkCardContent> $stored
     * @param list<string>          $uncardedUrls
     */
    public function cardsFor(Entry $entry, array $stored, array $uncardedUrls): EntryCardsModel
    {
        $quoted = $this->quotedTargetAmong((string) $entry->getContentHtml(), $uncardedUrls);
        $pageUrls = $quoted === null
            ? $uncardedUrls
            : array_values(array_filter($uncardedUrls, static fn (string $url): bool => $url !== $quoted->address));

        return new EntryCardsModel($stored, $this->pageCardsFor($pageUrls), $quoted);
    }

    /** @param list<string> $uncardedUrls */
    private function quotedTargetAmong(string $html, array $uncardedUrls): ?QuotedTargetModel
    {
        $target = QuoteLine::targetIn($html);
        if ($target === null || !\in_array($target, $uncardedUrls, true)) {
            return null;
        }
        try {
            return new QuotedTargetModel($target, $this->quotedPosts->quotedPostAt($target));
        } catch (QuotedPostUnavailableException $exception) {
            $this->logger->info('No quoted post at {url}', ['url' => $target, 'exception' => $exception::class]);

            return null;
        }
    }

    /**
     * @param list<string> $uncardedUrls
     *
     * @return list<PageCardModel>
     */
    private function pageCardsFor(array $uncardedUrls): array
    {
        $pageCards = [];
        foreach ($uncardedUrls as $uncardedUrl) {
            try {
                $pageCards[] = $this->pageCards->read($uncardedUrl);
            } catch (PageFetchException $exception) {
                $this->logger->warning(
                    'No link card for {url}',
                    ['url' => $uncardedUrl, 'exception' => $exception::class],
                );
            }
        }

        return $pageCards;
    }
}
