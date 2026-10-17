<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Repository\LinkCardRepository;
use App\Repository\ShareEnrichmentRepository;
use App\Repository\ThreadPostRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Completion\SelfThreadPass\SelfThreadPassInterface;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SelfThreadBudgetModel;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Share\Support\ShareTargets;
use App\Service\Social\ReplyThreadSource\ReplyThreadSourceInterface;
use App\Service\Social\Support\SocialPostAddress;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class SelfThreadEnricher implements SelfThreadPassInterface
{
    private const int THREADS_PER_PASS = 25;

    private const int THREADS_PER_COMPLETION = 2;

    private const int GIVEN_UP_PER_PASS = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShareEnrichmentRepository $enrichments,
        #[AutowireLocator('app.social_reply_thread_source', defaultIndexMethod: 'network')]
        private ContainerInterface $threads,
        private SelfReplyChain $chain,
        private SelfThreadFolder $folder,
        private ThreadPostRepository $threadPosts,
        private LinkCardRepository $linkCards,
        private NaiveUtcClock $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function enrich(Feed $feed): void
    {
        $this->guarded(fn () => $this->enrichQueued($feed));
    }

    /** @param list<ShareEnrichment> $queued self-thread rows, oldest first */
    public function complete(array $queued): void
    {
        $budget = SelfThreadBudgetModel::threads(self::THREADS_PER_COMPLETION);
        $this->guarded(fn () => $this->claimAndSettle(
            \array_slice($queued, 0, $budget->threads),
            $budget,
            $this->clock->now(),
        ));
    }

    /**
     * A failure is logged, unless it closed the EntityManager: rethrown, so a refresh aborts under this feed.
     *
     * @param callable(): void $enrichment
     */
    private function guarded(callable $enrichment): void
    {
        try {
            $enrichment();
        } catch (\Exception $exception) {
            if (!$this->entityManager->isOpen()) {
                throw $exception;
            }
            $this->logger->error('Self-thread enrichment failed', ['exception' => $exception]);
        }
    }

    /** Rows past the give-up window settle in their own lane, unasked, so they never take a thread's slot. */
    private function enrichQueued(Feed $feed): void
    {
        if (!$this->enrichments->hasPendingForFeed($feed, ShareEnrichmentKind::SelfThread)) {
            return;
        }
        $now = $this->clock->now();
        $this->giveUp(
            $this->enrichments->findClaimableGivenUpForFeed(
                $feed,
                ShareEnrichmentKind::SelfThread,
                $now,
                self::GIVEN_UP_PER_PASS,
            ),
            $now,
        );

        $budget = SelfThreadBudgetModel::threads(self::THREADS_PER_PASS);
        $queued = $this->enrichments->findClaimableLiveForFeed(
            $feed,
            ShareEnrichmentKind::SelfThread,
            $now,
            $budget->threads,
        );
        $this->claimAndSettle($queued, $budget, $now);
    }

    /** @param list<ShareEnrichment> $queued */
    private function giveUp(array $queued, \DateTimeImmutable $claimedAt): void
    {
        $claimed = $this->enrichments->claim($queued, $claimedAt);
        if ($claimed === []) {
            return;
        }
        foreach ($claimed as $row) {
            $this->handOnOrDrop($row);
        }
        $this->entityManager->flush();
        $this->enrichments->release(self::awaitingLinkCards($claimed), $claimedAt);
    }

    /**
     * Each row is flushed once settled, while the lease its fold was checked against still runs. A row handed to the
     * link-card pass is released only after that flush, or this pass would refill it.
     *
     * @param list<ShareEnrichment> $queued
     */
    private function claimAndSettle(array $queued, SelfThreadBudgetModel $budget, \DateTimeImmutable $claimedAt): void
    {
        $claimed = $this->enrichments->claim($queued, $claimedAt);
        if ($claimed === []) {
            return;
        }

        $unsettled = [];
        foreach ($claimed as $index => $row) {
            if (!$budget->fitsAfter($this->secondsSince($claimedAt))) {
                array_push($unsettled, ...\array_slice($claimed, $index));
                break;
            }
            if (!$this->settle($row, $claimedAt)) {
                $unsettled[] = $row;
                continue;
            }
            $this->entityManager->flush();
        }
        $this->enrichments->release([...$unsettled, ...self::awaitingLinkCards($claimed)], $claimedAt);
    }

    /** @return bool false when the row goes back to the queue for a later pass */
    private function settle(ShareEnrichment $row, \DateTimeImmutable $claimedAt): bool
    {
        $address = SocialPostAddress::of($row->getEntry());
        if ($address === null || $row->isQueuedBefore(ShareEnrichment::giveUpCutoff($claimedAt))) {
            $this->handOnOrDrop($row);

            return true;
        }

        return $this->foldThreadOf($row, $address, $claimedAt);
    }

    /**
     * A row whose lease another worker took over while its thread was read is left to that worker.
     *
     * @return bool false when the row goes back to the queue for a later pass
     */
    private function foldThreadOf(
        ShareEnrichment $row,
        SocialPostAddressModel $address,
        \DateTimeImmutable $claimedAt,
    ): bool {
        $thread = $this->threadOn($address);
        if ($thread->publicThreadThrottledForSeconds() > 0) {
            return false;
        }
        try {
            $chain = $this->chainOf($thread, $address);
        } catch (SocialNetworkThrottledException) {
            return false;
        }
        if ($this->enrichments->stillHeld([$row], $claimedAt) === []) {
            return true;
        }

        $entry = $row->getEntry();
        if (!$this->threadPosts->hasAnyFor($entry)) {
            $this->folder->fold($entry, $chain);
        }
        $this->handOnOrDrop($row);

        return true;
    }

    /** A shared root still linking an uncarded page goes to the link-card pass; the fold stored the replies' cards. */
    private function handOnOrDrop(ShareEnrichment $row): void
    {
        $entry = $row->getEntry();
        if (
            ShareTargets::isCardableShare($entry)
            && ShareTargets::uncardedOf($entry, $this->rootCardUrlsOf($entry)) !== []
        ) {
            $row->awaitLinkCards();

            return;
        }
        $this->entityManager->remove($row);
    }

    /** @return list<string> */
    private function rootCardUrlsOf(Entry $entry): array
    {
        $rootCards = $this->linkCards->contentsByEntryIds([$entry->requireId()])[$entry->requireId()] ?? [];

        return array_map(static fn (LinkCardContent $card): string => $card->url, $rootCards);
    }

    private function secondsSince(\DateTimeImmutable $claimedAt): float
    {
        return (float) $this->clock->now()->format('U.u') - (float) $claimedAt->format('U.u');
    }

    /**
     * A thread that is gone or cannot be read folds as an empty chain: the entry keeps its text.
     *
     * @return list<EntryCommentModel>
     *
     * @throws SocialNetworkThrottledException
     */
    private function chainOf(ReplyThreadSourceInterface $thread, SocialPostAddressModel $address): array
    {
        try {
            $answer = $thread->inPublic($address->address);

            return $this->chain->of($thread->repliesIn($address->address, $answer->body));
        } catch (SocialNetworkThrottledException $exception) {
            throw $exception;
        } catch (
            SocialPostNotFoundException
            | SocialNetworkUnreachableException
            | CommentsUnavailableException $exception
        ) {
            $this->logger->warning(
                'Self-reply thread of {address} could not be read',
                ['address' => $address->address, 'exception' => $exception],
            );

            return [];
        }
    }

    private function threadOn(SocialPostAddressModel $address): ReplyThreadSourceInterface
    {
        $thread = $this->threads->get($address->network->value);
        \assert($thread instanceof ReplyThreadSourceInterface);

        return $thread;
    }

    /**
     * @param list<ShareEnrichment> $claimed
     *
     * @return list<ShareEnrichment>
     */
    private static function awaitingLinkCards(array $claimed): array
    {
        return array_values(array_filter(
            $claimed,
            static fn (ShareEnrichment $row): bool => $row->getKind() === ShareEnrichmentKind::LinkCards,
        ));
    }
}
