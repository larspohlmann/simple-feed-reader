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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Builds queued posts' link cards, oldest first: one attempt each, the row marked done even when a page fails. */
final readonly class PostLinkCards
{
    private const int FETCHES_PER_CALL = SharedLinks::MAX;

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
        $now = $this->clock->now();
        $claimed = $this->enrichments->claim(
            array_values(array_filter(
                $queued,
                static fn (PostEnrichment $row): bool => isset($sharedUrlsByRowId[$row->requireId()]),
            )),
            $now,
        );
        if ($claimed === []) {
            return;
        }

        foreach ($claimed as $row) {
            $this->linkCards->add($row->getEntry(), $this->cardsFor($sharedUrlsByRowId[$row->requireId()]));
        }
        $this->entityManager->flush();
        $this->enrichments->markDone($claimed, $now);
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
