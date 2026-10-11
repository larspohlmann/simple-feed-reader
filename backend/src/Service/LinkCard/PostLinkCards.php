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

/**
 * Builds the link cards of queued posts, oldest first, within one call's page fetches. Each post gets one attempt:
 * a page that fails is logged and skipped, and the row is marked done either way.
 */
final readonly class PostLinkCards
{
    private const int FETCHES_PER_CALL = 3;

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
     * The oldest rows whose links fit the call's fetches, by row id. The first row is always taken, so a post sharing
     * more links than one call fetches gets cards for as many as it does.
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
            if ($remaining === 0) {
                break;
            }
            $sharedUrls = SharedLinks::of((string) $row->getEntry()->getContentHtml())->urls;
            if ($sharedUrlsByRowId !== [] && \count($sharedUrls) > $remaining) {
                break;
            }
            $sharedUrlsByRowId[$row->requireId()] = \array_slice($sharedUrls, 0, $remaining);
            $remaining -= \count($sharedUrlsByRowId[$row->requireId()]);
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
