<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Repository\LinkCardRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\Model\EntryCardsModel;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Share\Support\ShareTargets;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the cards a queued share's targets still lack, oldest share first: one attempt each, the row marked done
 * even when a page fails.
 */
final readonly class ShareLinkCards
{
    public function __construct(
        private PageCardReaderInterface $pageCards,
        private EntryCardWriter $cardWriter,
        private ShareEnrichmentRepository $enrichments,
        private LinkCardRepository $linkCards,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<ShareEnrichment> $queued link-card rows, oldest first
     */
    public function complete(array $queued, LinkCardBudgetModel $budget): int
    {
        $storedCards = $this->storedCards($queued);
        $uncardedUrlsByRowId = self::withinBudget($queued, $storedCards, $budget);
        $claimedAt = $this->clock->now();
        $claimed = $this->enrichments->claim(
            array_values(array_filter(
                $queued,
                static fn (ShareEnrichment $row): bool => isset($uncardedUrlsByRowId[$row->requireId()]),
            )),
            $claimedAt,
        );

        $cardsByRowId = [];
        foreach ($claimed as $index => $row) {
            $uncardedUrls = $uncardedUrlsByRowId[$row->requireId()];
            if ($index > 0 && !$budget->fitsAfter($this->secondsSince($claimedAt), \count($uncardedUrls))) {
                $this->enrichments->release(\array_slice($claimed, $index), $claimedAt);
                break;
            }
            $entry = $row->getEntry();
            $cardsByRowId[$row->requireId()] = new EntryCardsModel(
                $entry,
                $storedCards[$entry->requireId()] ?? [],
                $this->pageCardsFor($uncardedUrls),
            );
        }
        return $this->store($claimed, $cardsByRowId, $claimedAt);
    }

    /**
     * Only rows the claim still holds are written: no other worker cards their entries meanwhile.
     *
     * @param list<ShareEnrichment>       $claimed
     * @param array<int, EntryCardsModel> $cardsByRowId the rows this call worked
     */
    private function store(array $claimed, array $cardsByRowId, \DateTimeImmutable $claimedAt): int
    {
        $worked = array_values(array_filter(
            $claimed,
            static fn (ShareEnrichment $row): bool => isset($cardsByRowId[$row->requireId()]),
        ));
        $held = $this->enrichments->stillHeld($worked, $claimedAt);
        if ($held === []) {
            return 0;
        }

        foreach ($held as $row) {
            $this->cardWriter->write($cardsByRowId[$row->requireId()]);
        }
        $this->entityManager->flush();
        $this->enrichments->markDone($held, $claimedAt);

        return \count($held);
    }

    private function secondsSince(\DateTimeImmutable $claimedAt): float
    {
        return (float) $this->clock->now()->format('U.u') - (float) $claimedAt->format('U.u');
    }

    /**
     * @param list<ShareEnrichment> $rows
     *
     * @return array<int, list<LinkCardContent>> by entry id
     */
    private function storedCards(array $rows): array
    {
        return $this->linkCards->contentsByEntryIds(array_map(
            static fn (ShareEnrichment $row): int => $row->getEntry()->requireId(),
            $rows,
        ));
    }

    /**
     * The oldest rows whose uncarded links fit the budget, by row id. The first row always runs, so no entry is left
     * half-carded.
     *
     * @param list<ShareEnrichment>             $queued
     * @param array<int, list<LinkCardContent>> $storedCards by entry id
     *
     * @return array<int, list<string>>
     */
    private static function withinBudget(array $queued, array $storedCards, LinkCardBudgetModel $budget): array
    {
        $remaining = $budget->fetches;
        $uncardedUrlsByRowId = [];
        foreach ($queued as $row) {
            $entry = $row->getEntry();
            $cardedUrls = array_map(
                static fn (LinkCardContent $card): string => $card->url,
                $storedCards[$entry->requireId()] ?? [],
            );
            $uncardedUrls = ShareTargets::uncardedOf($entry, $cardedUrls);
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
