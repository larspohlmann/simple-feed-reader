<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Headline;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Repository\ShareEnrichmentRepository;
use App\Service\LinkCard\Model\LinkCardBudgetModel;
use App\Service\LinkCard\ShareLinkCards;
use App\Service\Reader\Exception\PageFetchException;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ShareLinkCardsWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StubPageCardReader;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;

final class ShareLinkCardsTest extends DbTestCase
{
    private MockClock $clock;
    private StubPageCardReader $pages;
    private RecordingLogger $logger;
    private ShareLinkCards $shareLinkCards;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->pages = new StubPageCardReader();
        $this->logger = new RecordingLogger();
        $this->shareLinkCards = ShareLinkCardsWiring::reading(
            $this->pages,
            $this->entityManager,
            $this->clock,
            $this->logger,
        );
        $this->feed = new Feed('https://mastodon.example/@someone.rss');
        $this->feed->setSiteUrl('https://mastodon.example/@someone');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testBuildsTheCardsOfAQueuedPostOnceAndMarksItDone(): void
    {
        $post = $this->queuedPost(1, '<p><a href="https://example.com/a">https://example.com/a</a> '
            . '<a href="https://example.com/b">https://example.com/b</a></p>');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->shareLinkCards->complete([$this->rowOf($post)], self::fiveFetches());

        self::assertSame(2, $this->pages->reads());
        self::assertSame(
            ['Card for https://example.com/a', 'Card for https://example.com/b'],
            $this->cardTitles($post),
        );
        self::assertSame([], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    public function testACallSpendsFiveFetchesOnTheOldestPostsThatFit(): void
    {
        $oldest = $this->queuedPost(1, self::sharing(1, 3));
        $middle = $this->queuedPost(2, self::sharing(2, 3));
        $newest = $this->queuedPost(3, self::sharing(3, 2));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        self::assertSame(3, $this->pages->reads());
        self::assertCount(3, $this->cardTitles($oldest));
        self::assertSame([], $this->cardTitles($middle));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        self::assertSame(8, $this->pages->reads());
        self::assertCount(3, $this->cardTitles($middle));
        self::assertCount(2, $this->cardTitles($newest));
    }

    public function testAFiveLinkPostGetsEveryCardAndTheNextPostWaitsForTheNextCall(): void
    {
        $fiveLinks = $this->queuedPost(1, self::sharing(1, 5));
        $twoLinks = $this->queuedPost(2, self::sharing(2, 2));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        self::assertSame(5, $this->pages->reads());
        self::assertCount(5, $this->cardTitles($fiveLinks));
        self::assertSame([], $this->cardTitles($twoLinks));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        self::assertSame(7, $this->pages->reads());
        self::assertCount(2, $this->cardTitles($twoLinks));
        self::assertSame([], $this->claimable());
    }

    public function testARowAnotherWorkerHoldsIsLeftToIt(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $this->enrichments()->claim([$this->rowOf($post)], $this->clock->now());

        $this->shareLinkCards->complete([$this->rowOf($post)], self::fiveFetches());

        self::assertSame(0, $this->pages->reads());
        self::assertSame([$post->requireId()], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    public function testACallPastItsDeadlineReleasesThePostsItNeverStarted(): void
    {
        $posts = array_map(
            fn (int $number): Entry => $this->queuedPost($number, self::sharing($number, 1)),
            range(1, 5),
        );
        $slowPages = $this->slowPages(15);
        $shareLinkCards = ShareLinkCardsWiring::reading($slowPages, $this->entityManager, $this->clock);

        $shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(3, $slowPages->reads());
        self::assertCount(1, $this->cardTitles($posts[2]));
        self::assertSame(
            [$posts[3]->requireId(), $posts[4]->requireId()],
            array_map(static fn (ShareEnrichment $row): int => $row->getEntry()->requireId(), $this->claimable()),
        );
    }

    public function testAHolderWhoseLeaseWasTakenOverNeitherCardsNorFinishesThePost(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $takeOver = new StubPageCardReader(function () use ($post): void {
            $this->clock->sleep(61);
            $this->enrichments()->claim([$this->rowOf($post)], $this->clock->now());
        });

        $completed = ShareLinkCardsWiring::reading($takeOver, $this->entityManager, $this->clock)
            ->complete($this->claimable(), self::fiveFetches());

        self::assertSame(0, $completed);
        self::assertSame([], $this->cardTitles($post));
        self::assertSame([$post->requireId()], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    public function testAPostWhoseLinksAreAllCardedIsDoneWithoutAFetch(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $this->storedCard($post, 0, 'https://example.com/1/1');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(0, $this->pages->reads());
        self::assertSame(['Stored before'], $this->cardTitles($post));
        self::assertSame([], $this->claimable());
    }

    public function testOnlyTheUncardedLinksAreFetchedAndTheirCardsFollowTheStoredOnes(): void
    {
        $this->offsetEntryIdsFromRowIds();
        $post = $this->queuedPost(1, self::sharing(1, 3));
        $this->storedCard($post, 0, 'https://example.com/1/2');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(2, $this->pages->reads());
        self::assertSame(
            ['Stored before', 'Card for https://example.com/1/1', 'Card for https://example.com/1/3'],
            $this->cardTitles($post),
        );
    }

    public function testTheBudgetCountsOnlyTheUncardedLinks(): void
    {
        $carded = $this->queuedPost(1, self::sharing(1, 5));
        foreach (range(1, 4) as $link) {
            $this->storedCard($carded, $link - 1, 'https://example.com/1/' . $link);
        }
        $next = $this->queuedPost(2, self::sharing(2, 4));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(5, $this->pages->reads());
        self::assertCount(5, $this->cardTitles($carded));
        self::assertCount(4, $this->cardTitles($next));
    }

    public function testAMissingPageGivesNoCardAndTheRowIsStillDone(): void
    {
        $post = $this->queuedPost(1, '<p>https://example.com/missing https://example.com/found</p>');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(['Card for https://example.com/found'], $this->cardTitles($post));
        self::assertSame([], $this->claimable());
        self::assertSame([[
            'level' => 'warning',
            'message' => 'No link card for {url}',
            'context' => ['url' => 'https://example.com/missing', 'exception' => PageFetchException::class],
        ]], $this->logger->records);
    }

    public function testAPostThatNoLongerSharesALinkIsDoneWithoutAFetch(): void
    {
        $this->queuedPost(1, null);

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(0, $this->pages->reads());
        self::assertSame([], $this->claimable());
    }

    public function testATitledShareGetsTheCardOfItsTarget(): void
    {
        $story = $this->queuedTitledShare(1, 'https://example.com/story');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(['Card for https://example.com/story'], $this->cardTitles($story));
        self::assertSame([], $this->claimable());
    }

    public function testAFailedFlushIsRethrownAndLeavesThePostPending(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $closingEntityManager = new class ($this->entityManager) extends EntityManagerDecorator {
            public function flush(): void
            {
                throw new \RuntimeException('Simulated flush failure.');
            }
        };

        try {
            $failing = ShareLinkCardsWiring::reading($this->pages, $closingEntityManager, $this->clock);
            $failing->complete($this->claimable(), self::fiveFetches());
            self::fail('A failed flush must be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated flush failure.', $exception->getMessage());
        }

        self::assertSame([$post->requireId()], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    public function testACallSpendsNoMoreFetchesThanItsBudget(): void
    {
        $oldest = $this->queuedPost(1, self::sharing(1, 1));
        $newer = $this->queuedPost(2, self::sharing(2, 1));

        $completed = $this->shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(1));

        self::assertSame(1, $completed);
        self::assertSame(1, $this->pages->reads());
        self::assertCount(1, $this->cardTitles($oldest));
        self::assertSame([$newer->requireId()], $this->enrichments()->pendingEntryIdsAmong([$newer->requireId()]));
    }

    public function testAPostWithMoreLinksThanTheBudgetIsStillCardedWhole(): void
    {
        $threeLinks = $this->queuedPost(1, self::sharing(1, 3));

        $completed = $this->shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(1));

        self::assertSame(1, $completed);
        self::assertSame(3, $this->pages->reads());
        self::assertCount(3, $this->cardTitles($threeLinks));
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function pagesAgainstATwoFetchBudget(): iterable
    {
        yield 'a page slower than its share of the budget ends the call' => [9, 1, 1, 2];
        yield 'two full-length fetches fit the budget' => [8, 2, 2, 1];
    }

    #[DataProvider('pagesAgainstATwoFetchBudget')]
    public function testACallStopsAtItsBudgetsDeadline(
        int $secondsPerPage,
        int $expectedRequests,
        int $expectedCompleted,
        int $expectedStillClaimable,
    ): void {
        foreach (range(1, 3) as $number) {
            $this->queuedPost($number, self::sharing($number, 1));
        }
        $slowPages = $this->slowPages($secondsPerPage);
        $shareLinkCards = ShareLinkCardsWiring::reading($slowPages, $this->entityManager, $this->clock);

        $completed = $shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(2));

        self::assertSame($expectedCompleted, $completed);
        self::assertSame($expectedRequests, $slowPages->reads());
        self::assertCount($expectedStillClaimable, $this->claimable());
    }

    private function slowPages(int $secondsPerPage): StubPageCardReader
    {
        return new StubPageCardReader(fn () => $this->clock->sleep($secondsPerPage));
    }

    private static function fiveFetches(): LinkCardBudgetModel
    {
        return LinkCardBudgetModel::fetches(5);
    }

    private static function sharing(int $post, int $links): string
    {
        return '<p>' . implode(' ', array_map(
            static fn (int $link): string => 'https://example.com/' . $post . '/' . $link,
            range(1, $links),
        )) . '</p>';
    }

    /** Queued a minute apart, in the order the test creates them. */
    private function queuedPost(int $number, ?string $contentHtml, ?string $url = null): Entry
    {
        $entry = $this->entry($number, $contentHtml, $url);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new ShareEnrichment(
            $entry,
            ShareEnrichmentKind::LinkCards,
            $this->clock->now()->modify(sprintf('-%d minutes', 10 - $number)),
        ));
        $this->entityManager->flush();

        return $entry;
    }

    private function queuedTitledShare(int $number, string $target): Entry
    {
        $entry = $this->queuedPost($number, null, $target);
        $entry->setHeadline(Headline::fromFeed('A story'));
        $entry->setShareKind(ShareKind::Link);
        $this->entityManager->flush();

        return $entry;
    }

    private function offsetEntryIdsFromRowIds(): void
    {
        $this->entityManager->persist($this->entry(0, null));
    }

    private function entry(int $number, ?string $contentHtml, ?string $url = null): Entry
    {
        $now = $this->clock->now();
        $entry = new Entry($this->feed, 'https://mastodon.example/@someone/' . $number, $url, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);
        $entry->setHeadline(Headline::derived('Post'));
        $entry->getImage()->storePending(null, null, null);

        return $entry;
    }

    private function storedCard(Entry $entry, int $position, string $url): void
    {
        $this->entityManager->persist(
            new LinkCard($entry, $position, new LinkCardContent($url, 'Stored before', null, null, 'example.com')),
        );
        $this->entityManager->flush();
    }

    /** @return list<ShareEnrichment> */
    private function claimable(): array
    {
        return $this->enrichments()->findClaimableFor(
            $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $this->feed]),
            $this->clock->now(),
        );
    }

    private function rowOf(Entry $entry): ShareEnrichment
    {
        $row = $this->enrichments()->findOneBy(['entry' => $entry]);
        self::assertInstanceOf(ShareEnrichment::class, $row);

        return $row;
    }

    /** @return list<string> */
    private function cardTitles(Entry $entry): array
    {
        $cardsByEntryId = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);

        return array_map(
            static fn (LinkCardContent $card): string => $card->title,
            $cardsByEntryId[$entry->requireId()] ?? [],
        );
    }

    private function enrichments(): ShareEnrichmentRepository
    {
        return PostEnrichers::enrichments($this->entityManager);
    }
}
