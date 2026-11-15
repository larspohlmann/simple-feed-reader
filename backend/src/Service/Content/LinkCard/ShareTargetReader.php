<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Exception\QuotedPostUnavailableException;
use App\Service\Content\LinkCard\Model\EntryCardsModel;
use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\LinkCard\Model\QuotedTargetModel;
use App\Service\Content\LinkCard\Model\ShareFetchPlanModel;
use App\Service\Content\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Content\LinkCard\QuotedPostSource\QuotedPostSourceInterface;
use App\Service\Content\LinkCard\Support\QuoteLine;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use Psr\Log\LoggerInterface;

/** Reads what a share's uncarded targets show: the post its "RE:" line quotes, and a page card for every other link. */
final readonly class ShareTargetReader
{
    public function __construct(
        private PageCardReaderInterface $pageCards,
        private QuotedPostSourceInterface $quotedPosts,
        private PostedUrlCheck $postedUrls,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<LinkCardContent> $stored */
    public function cardsFor(Entry $entry, array $stored, ShareFetchPlanModel $plan): EntryCardsModel
    {
        $quoted = $this->quotedTargetAmong((string) $entry->getText()->getContentHtml(), $plan->urls);
        $pageUrls = $quoted === null
            ? $plan->urls
            : array_values(array_filter($plan->urls, static fn (string $url): bool => $url !== $quoted->address));

        return new EntryCardsModel($stored, $this->pageCardsFor($pageUrls, $plan->postedForms), $quoted);
    }

    /** @param list<string> $uncardedUrls */
    private function quotedTargetAmong(string $html, array $uncardedUrls): ?QuotedTargetModel
    {
        $target = TrackingFreeUrl::orNull(QuoteLine::targetIn($html));
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
     * @param list<string>          $uncardedUrls
     * @param array<string, string> $postedForms
     *
     * @return list<PageCardModel>
     */
    private function pageCardsFor(array $uncardedUrls, array $postedForms): array
    {
        $pageCards = [];
        foreach ($uncardedUrls as $uncardedUrl) {
            try {
                $pageCards[] = isset($postedForms[$uncardedUrl])
                    ? $this->postedUrls->cardAt($uncardedUrl, $postedForms[$uncardedUrl])
                    : $this->pageCards->read($uncardedUrl);
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
