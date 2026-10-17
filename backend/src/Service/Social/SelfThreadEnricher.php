<?php

declare(strict_types=1);

namespace App\Service\Social;

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
        $this->guarded(fn () => $this->claimAndSettle($queued, $this->clock->now()));
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

    private function enrichQueued(Feed $feed): void
    {
        if (!$this->enrichments->hasPendingForFeed($feed, ShareEnrichmentKind::SelfThread)) {
            return;
        }
        $now = $this->clock->now();
        $queued = $this->enrichments->findClaimableForFeed(
            $feed,
            ShareEnrichmentKind::SelfThread,
            $now,
            self::THREADS_PER_PASS,
        );
        $this->claimAndSettle($queued, $now);
    }

    /**
     * A row handed to the link-card pass is released only once its new kind is flushed, or this pass would refill it.
     *
     * @param list<ShareEnrichment> $queued
     */
    private function claimAndSettle(array $queued, \DateTimeImmutable $claimedAt): void
    {
        $claimed = $this->enrichments->claim($queued, $claimedAt);
        if ($claimed === []) {
            return;
        }

        $unsettled = [];
        foreach ($this->enrichments->stillHeld($claimed, $claimedAt) as $row) {
            if (!$this->settle($row, $claimedAt)) {
                $unsettled[] = $row;
            }
        }
        $this->entityManager->flush();
        $this->enrichments->release([...$unsettled, ...self::awaitingLinkCards($claimed)], $claimedAt);
    }

    /** @return bool whether the row is settled; false leaves it queued for a later pass */
    private function settle(ShareEnrichment $row, \DateTimeImmutable $claimedAt): bool
    {
        $entry = $row->getEntry();
        $address = SocialPostAddress::of($entry);
        if ($address === null || $row->isQueuedBefore(self::giveUpCutoff($claimedAt))) {
            $this->handOnOrDrop($row);

            return true;
        }
        $thread = $this->threadOn($address);
        if ($thread->publicThreadThrottledForSeconds() > 0) {
            return false;
        }
        try {
            $chain = $this->chainOf($thread, $address);
        } catch (SocialNetworkThrottledException) {
            return false;
        }

        if (!$this->threadPosts->hasAnyFor($entry)) {
            $this->folder->fold($entry, $chain);
        }
        $this->handOnOrDrop($row);

        return true;
    }

    /** The link-card pass takes a row whose root still links a page without a card; the fold stored the replies' own. */
    private function handOnOrDrop(ShareEnrichment $row): void
    {
        $entry = $row->getEntry();
        $rootCards = $this->linkCards->contentsByEntryIds([$entry->requireId()])[$entry->requireId()] ?? [];
        $cardedUrls = array_map(static fn (LinkCardContent $card): string => $card->url, $rootCards);
        if (ShareTargets::uncardedOf($entry, $cardedUrls) !== []) {
            $row->awaitLinkCards();

            return;
        }
        $this->entityManager->remove($row);
    }

    private static function giveUpCutoff(\DateTimeImmutable $claimedAt): \DateTimeImmutable
    {
        return $claimedAt->sub(new \DateInterval(ShareEnrichment::APPVIEW_GIVE_UP_AFTER));
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
