<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\Share;
use App\Entity\ShareEnrichment;
use App\Repository\LinkCardRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Support\QuoteLine;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\LinkCard\Support\ShareTargets;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Sanitize\EntrySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the cards a queued share's targets still lack, oldest share first: one attempt each, the row marked done
 * even when a page fails. A carded quote's RE: line leaves the body.
 */
final readonly class ShareLinkCards
{
    private const int FETCHES_PER_CALL = SharedLinks::MAX;
    private const float DEADLINE_SECONDS = self::FETCHES_PER_CALL * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS;

    public function __construct(
        private PageCardReader $pageCards,
        private ShareEnrichmentRepository $enrichments,
        private LinkCardRepository $linkCards,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private EntrySanitizer $sanitizer,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<ShareEnrichment> $queued link-card rows, oldest first */
    public function complete(array $queued): void
    {
        $uncardedUrlsByRowId = self::withinBudget($queued, $this->cardedUrls($queued));
        $claimedAt = $this->clock->now();
        $claimed = $this->enrichments->claim(
            array_values(array_filter(
                $queued,
                static fn (ShareEnrichment $row): bool => isset($uncardedUrlsByRowId[$row->requireId()]),
            )),
            $claimedAt,
        );

        $pageCardsByRowId = [];
        foreach ($claimed as $index => $row) {
            $uncardedUrls = $uncardedUrlsByRowId[$row->requireId()];
            if ($index > 0 && !$this->hasTimeFor($uncardedUrls, $claimedAt)) {
                $this->enrichments->release(\array_slice($claimed, $index), $claimedAt);
                break;
            }
            $pageCardsByRowId[$row->requireId()] = $this->pageCardsFor($uncardedUrls);
        }
        $this->store($claimed, $pageCardsByRowId, $claimedAt);
    }

    /**
     * Only rows the claim still holds are written: no other worker cards their entries meanwhile.
     *
     * @param list<ShareEnrichment>                $claimed
     * @param array<int, list<PageCardModel>> $pageCardsByRowId the rows this call worked
     */
    private function store(array $claimed, array $pageCardsByRowId, \DateTimeImmutable $claimedAt): void
    {
        $worked = array_values(array_filter(
            $claimed,
            static fn (ShareEnrichment $row): bool => isset($pageCardsByRowId[$row->requireId()]),
        ));
        $held = $this->enrichments->stillHeld($worked, $claimedAt);
        if ($held === []) {
            return;
        }

        $cardedUrls = $this->cardedUrls($held);
        foreach ($held as $row) {
            $pageCards = $pageCardsByRowId[$row->requireId()];
            $cards = self::cardsOf($pageCards);
            $storedCount = \count($cardedUrls[$row->getEntry()->requireId()] ?? []);
            $this->linkCards->add($row->getEntry(), $storedCount, $cards);
            $this->dropCardedQuoteLines($row->getEntry(), $cards);
            $this->settleMediaTarget($row->getEntry(), $pageCards);
        }
        $this->entityManager->flush();
        $this->enrichments->markDone($held, $claimedAt);
    }

    /**
     * @param list<PageCardModel> $pageCards
     *
     * @return list<LinkCardContent>
     */
    private static function cardsOf(array $pageCards): array
    {
        return array_values(array_filter(array_map(
            static fn (PageCardModel $pageCard): ?LinkCardContent => $pageCard->card,
            $pageCards,
        )));
    }

    /**
     * A titled share whose page is a picture or video file is shown as that medium. A post keeps its link card: its
     * own media, not a linked file, make it a media post.
     *
     * @param list<PageCardModel> $pageCards
     */
    private function settleMediaTarget(Entry $entry, array $pageCards): void
    {
        $target = $entry->getShare()->url;
        $medium = $pageCards[0]->medium ?? null;
        if ($target === null || $medium === null) {
            return;
        }
        $entry->setShare(Share::media($target));
        $entry->putMediumFirst($medium);
    }

    /** @param list<LinkCardContent> $cards */
    private function dropCardedQuoteLines(Entry $entry, array $cards): void
    {
        $html = (string) $entry->getContentHtml();
        $withoutQuotes = $html;
        foreach ($cards as $card) {
            $withoutQuotes = QuoteLine::removedFrom($withoutQuotes, $card->url);
        }
        if ($withoutQuotes === $html) {
            return;
        }

        $sanitized = $this->sanitizer->sanitize($withoutQuotes);
        $entry->setContentHtml($sanitized);
        $entry->setSummary(EntrySnippet::from($sanitized));
    }

    /**
     * A later entry starts only if all its links can still be fetched before the deadline, well inside the lease.
     *
     * @param list<string> $uncardedUrls
     */
    private function hasTimeFor(array $uncardedUrls, \DateTimeImmutable $claimedAt): bool
    {
        $elapsed = (float) $this->clock->now()->format('U.u') - (float) $claimedAt->format('U.u');

        return $elapsed + \count($uncardedUrls) * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS <= self::DEADLINE_SECONDS;
    }

    /**
     * @param list<ShareEnrichment> $rows
     *
     * @return array<int, list<string>> by entry id
     */
    private function cardedUrls(array $rows): array
    {
        $cards = $this->linkCards->contentsByEntryIds(array_map(
            static fn (ShareEnrichment $row): int => $row->getEntry()->requireId(),
            $rows,
        ));

        return array_map(
            static fn (array $entryCards): array => array_map(
                static fn (LinkCardContent $card): string => $card->url,
                $entryCards,
            ),
            $cards,
        );
    }

    /**
     * The oldest rows whose uncarded links fit the call's fetches, by row id. The first row always runs, so no entry is
     * left half-carded.
     *
     * @param list<ShareEnrichment>     $queued
     * @param array<int, list<string>> $cardedUrls by entry id
     *
     * @return array<int, list<string>>
     */
    private static function withinBudget(array $queued, array $cardedUrls): array
    {
        $remaining = self::FETCHES_PER_CALL;
        $uncardedUrlsByRowId = [];
        foreach ($queued as $row) {
            $entry = $row->getEntry();
            $uncardedUrls = ShareTargets::uncardedOf($entry, $cardedUrls[$entry->requireId()] ?? []);
            if ($uncardedUrlsByRowId !== [] && \count($uncardedUrls) > $remaining) {
                break;
            }
            $uncardedUrlsByRowId[$row->requireId()] = $uncardedUrls;
            $remaining -= \count($uncardedUrls);
        }

        return $uncardedUrlsByRowId;
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
