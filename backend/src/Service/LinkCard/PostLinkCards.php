<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Entity\LinkCardContent;
use App\Entity\PostEnrichment;
use App\Repository\LinkCardRepository;
use App\Repository\PostEnrichmentRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\HtmlPageFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Builds queued posts' link cards, oldest first: one attempt each, the row marked done even when a page fails. */
final readonly class PostLinkCards
{
    private const int FETCHES_PER_CALL = SharedLinks::MAX;
    private const float DEADLINE_SECONDS = self::FETCHES_PER_CALL * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS;

    public function __construct(
        private PageCardReader $pageCards,
        private PostEnrichmentRepository $enrichments,
        private LinkCardRepository $linkCards,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /** @param list<PostEnrichment> $queued link-card rows, oldest first */
    public function complete(array $queued): void
    {
        $sharedUrlsByRowId = self::withinBudget($queued);
        $claimedAt = $this->clock->now();
        $claimed = $this->enrichments->claim(
            array_values(array_filter(
                $queued,
                static fn (PostEnrichment $row): bool => isset($sharedUrlsByRowId[$row->requireId()]),
            )),
            $claimedAt,
        );

        $cardsByRowId = [];
        foreach ($claimed as $index => $row) {
            $sharedUrls = $sharedUrlsByRowId[$row->requireId()];
            if ($index > 0 && !$this->hasTimeFor($sharedUrls, $claimedAt)) {
                $this->enrichments->release(\array_slice($claimed, $index), $claimedAt);
                break;
            }
            $cardsByRowId[$row->requireId()] = $this->cardsFor($sharedUrls);
        }
        $this->store($claimed, $cardsByRowId, $claimedAt);
    }

    /**
     * Only rows the claim still holds are written, and never over cards another worker stored meanwhile.
     *
     * @param list<PostEnrichment>                $claimed
     * @param array<int, list<LinkCardContent>> $cardsByRowId the rows this call worked
     */
    private function store(array $claimed, array $cardsByRowId, \DateTimeImmutable $claimedAt): void
    {
        $worked = array_values(array_filter(
            $claimed,
            static fn (PostEnrichment $row): bool => isset($cardsByRowId[$row->requireId()]),
        ));
        $held = $this->enrichments->stillHeld($worked, $claimedAt);
        if ($held === []) {
            return;
        }

        $carded = array_flip($this->linkCards->entryIdsWithCards(array_map(
            static fn (PostEnrichment $row): int => $row->getEntry()->requireId(),
            $held,
        )));
        foreach ($held as $row) {
            if (!isset($carded[$row->getEntry()->requireId()])) {
                $this->linkCards->add($row->getEntry(), $cardsByRowId[$row->requireId()]);
            }
        }
        $this->entityManager->flush();
        $this->enrichments->markDone($held, $claimedAt);
    }

    /**
     * A later post starts only if all its links can still be fetched before the deadline, well inside the lease.
     *
     * @param list<string> $sharedUrls
     */
    private function hasTimeFor(array $sharedUrls, \DateTimeImmutable $claimedAt): bool
    {
        $elapsed = (float) $this->clock->now()->format('U.u') - (float) $claimedAt->format('U.u');

        return $elapsed + \count($sharedUrls) * HtmlPageFetcher::HEAD_MAX_DURATION_SECONDS <= self::DEADLINE_SECONDS;
    }

    /**
     * The oldest rows whose links fit the call's fetches, by row id. The first row always runs, so no post is left
     * half-carded.
     *
     * @param list<PostEnrichment> $queued
     *
     * @return array<int, list<string>>
     */
    private static function withinBudget(array $queued): array
    {
        $remaining = self::FETCHES_PER_CALL;
        $sharedUrlsByRowId = [];
        foreach ($queued as $row) {
            $sharedUrls = SharedLinks::of((string) $row->getEntry()->getContentHtml())->urls;
            if ($sharedUrlsByRowId !== [] && \count($sharedUrls) > $remaining) {
                break;
            }
            $sharedUrlsByRowId[$row->requireId()] = $sharedUrls;
            $remaining -= \count($sharedUrls);
        }

        return $sharedUrlsByRowId;
    }

    /**
     * @param list<string> $sharedUrls
     *
     * @return list<LinkCardContent>
     */
    private function cardsFor(array $sharedUrls): array
    {
        $cards = [];
        foreach ($sharedUrls as $sharedUrl) {
            try {
                $cards[] = $this->pageCards->read($sharedUrl);
            } catch (PageFetchException $exception) {
                $this->logger->warning(
                    'No link card for {url}',
                    ['url' => $sharedUrl, 'exception' => $exception::class],
                );
            }
        }

        return array_values(array_filter($cards));
    }
}
