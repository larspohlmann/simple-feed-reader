<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\Share;
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
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShareLinkCardsTest extends DbTestCase
{
    private MockClock $clock;
    private MockHttpClient $client;
    private RecordingLogger $logger;
    private ShareLinkCards $shareLinkCards;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->client = new MockHttpClient(self::page(...));
        $this->logger = new RecordingLogger();
        $this->shareLinkCards = ShareLinkCardsWiring::build(
            $this->entityManager,
            $this->client,
            $this->clock,
            $this->logger,
        );
        $this->feed = new Feed('https://mastodon.example/@someone.rss');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testBuildsTheCardsOfAQueuedPostOnceAndMarksItDone(): void
    {
        $post = $this->queuedPost(1, '<p><a href="https://example.com/a">https://example.com/a</a> '
            . '<a href="https://example.com/b">https://example.com/b</a></p>');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->shareLinkCards->complete([$this->rowOf($post)], self::fiveFetches());

        self::assertSame(2, $this->client->getRequestsCount());
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
        self::assertSame(3, $this->client->getRequestsCount());
        self::assertCount(3, $this->cardTitles($oldest));
        self::assertSame([], $this->cardTitles($middle));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        self::assertSame(8, $this->client->getRequestsCount());
        self::assertCount(3, $this->cardTitles($middle));
        self::assertCount(2, $this->cardTitles($newest));
    }

    public function testAFiveLinkPostGetsEveryCardAndTheNextPostWaitsForTheNextCall(): void
    {
        $fiveLinks = $this->queuedPost(1, self::sharing(1, 5));
        $twoLinks = $this->queuedPost(2, self::sharing(2, 2));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        self::assertSame(5, $this->client->getRequestsCount());
        self::assertCount(5, $this->cardTitles($fiveLinks));
        self::assertSame([], $this->cardTitles($twoLinks));

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        self::assertSame(7, $this->client->getRequestsCount());
        self::assertCount(2, $this->cardTitles($twoLinks));
        self::assertSame([], $this->claimable());
    }

    public function testARowAnotherWorkerHoldsIsLeftToIt(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $this->enrichments()->claim([$this->rowOf($post)], $this->clock->now());

        $this->shareLinkCards->complete([$this->rowOf($post)], self::fiveFetches());

        self::assertSame(0, $this->client->getRequestsCount());
        self::assertSame([$post->requireId()], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    public function testACallPastItsDeadlineReleasesThePostsItNeverStarted(): void
    {
        $posts = array_map(
            fn (int $number): Entry => $this->queuedPost($number, self::sharing($number, 1)),
            range(1, 5),
        );
        $slowPages = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->clock->sleep(15);

            return self::page($method, $url);
        });
        $shareLinkCards = ShareLinkCardsWiring::build($this->entityManager, $slowPages, $this->clock);

        $shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(3, $slowPages->getRequestsCount());
        self::assertCount(1, $this->cardTitles($posts[2]));
        self::assertSame(
            [$posts[3]->requireId(), $posts[4]->requireId()],
            array_map(static fn (ShareEnrichment $row): int => $row->getEntry()->requireId(), $this->claimable()),
        );
    }

    public function testAHolderWhoseLeaseWasTakenOverNeitherCardsNorFinishesThePost(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $takeOver = new MockHttpClient(function (string $method, string $url) use ($post): MockResponse {
            $this->clock->sleep(61);
            $this->enrichments()->claim([$this->rowOf($post)], $this->clock->now());

            return self::page($method, $url);
        });

        ShareLinkCardsWiring::build($this->entityManager, $takeOver, $this->clock)
            ->complete($this->claimable(), self::fiveFetches());

        self::assertSame([], $this->cardTitles($post));
        self::assertSame([$post->requireId()], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    public function testAPostWhoseLinksAreAllCardedIsDoneWithoutAFetch(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $this->storedCard($post, 0, 'https://example.com/1/1');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(0, $this->client->getRequestsCount());
        self::assertSame(['Stored before'], $this->cardTitles($post));
        self::assertSame([], $this->claimable());
    }

    public function testOnlyTheUncardedLinksAreFetchedAndTheirCardsFollowTheStoredOnes(): void
    {
        $this->offsetEntryIdsFromRowIds();
        $post = $this->queuedPost(1, self::sharing(1, 3));
        $this->storedCard($post, 0, 'https://example.com/1/2');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(2, $this->client->getRequestsCount());
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

        self::assertSame(5, $this->client->getRequestsCount());
        self::assertCount(5, $this->cardTitles($carded));
        self::assertCount(4, $this->cardTitles($next));
    }

    public function testACardedQuoteLeavesTheBodyAndTheSummaryIsDerivedAgain(): void
    {
        $post = $this->queuedPost(1, '<p>RE: <a href="https://q.example/@a/1">https://q.example/@a/1</a></p>'
            . '<p>Reposting for the morning crew</p>');
        $post->setSummary('RE: https://q.example/@a/1 Reposting for the morning crew');
        $this->entityManager->flush();

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->entityManager->refresh($post);

        self::assertSame(['Card for https://q.example/@a/1'], $this->cardTitles($post));
        self::assertSame('<p>Reposting for the morning crew</p>', $post->getContentHtml());
        self::assertSame('Reposting for the morning crew', $post->getSummary());
    }

    public function testAQuoteOnlyPostEndsWithNoBodyAndNoSummary(): void
    {
        $post = $this->queuedPost(1, '<p>RE: <a href="https://q.example/@a/1">https://q.example/@a/1</a></p>');
        $post->setSummary('RE: https://q.example/@a/1');
        $this->entityManager->flush();

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->entityManager->refresh($post);

        self::assertSame(['Card for https://q.example/@a/1'], $this->cardTitles($post));
        self::assertNull($post->getContentHtml());
        self::assertNull($post->getSummary());
    }

    public function testAQuoteWhosePageFailsKeepsItsLine(): void
    {
        $body = '<p>RE: <a href="https://q.example/missing">https://q.example/missing</a></p><p>Look.</p>';
        $post = $this->queuedPost(1, $body);

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->entityManager->refresh($post);

        self::assertSame([], $this->cardTitles($post));
        self::assertSame($body, $post->getContentHtml());
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

    public function testAPageWithoutATitleGivesNoCardAndLeavesNoGapInTheOrder(): void
    {
        $post = $this->queuedPost(1, '<p>https://example.com/untitled https://example.com/found</p>');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(['Card for https://example.com/found'], $this->cardTitles($post));
        self::assertSame(
            [0],
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT position FROM link_card WHERE entry_id = ?',
                [$post->requireId()],
            ),
        );
    }

    public function testAPostThatNoLongerSharesALinkIsDoneWithoutAFetch(): void
    {
        $this->queuedPost(1, null);

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(0, $this->client->getRequestsCount());
        self::assertSame([], $this->claimable());
    }

    public function testATitledShareGetsTheCardOfItsTarget(): void
    {
        $story = $this->queuedTitledShare(1, 'https://example.com/story');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());

        self::assertSame(['Card for https://example.com/story'], $this->cardTitles($story));
        self::assertSame([], $this->claimable());
    }

    public function testATitledShareWhoseTargetIsAPictureBecomesAMediaShare(): void
    {
        $chart = $this->queuedTitledShare(1, 'https://i.example.com/chart');

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->entityManager->refresh($chart);

        self::assertEquals(Share::media('https://i.example.com/chart'), $chart->getShare());
        self::assertSame('https://i.example.com/chart', $chart->getMedia()[0]->url);
    }

    public function testATitledShareWhosePictureIsAlreadyAMediumMovesItFirstInsteadOfAddingItAgain(): void
    {
        $chart = $this->queuedTitledShare(1, 'https://i.example.com/chart');
        $chart->setMedia([
            new EntryMedium('https://cdn.example/thumb.jpg', 'image'),
            new EntryMedium('https://i.example.com/chart', 'image', 800, 600),
        ], []);
        $this->entityManager->flush();

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->entityManager->refresh($chart);

        self::assertEquals(
            [
                new EntryMedium('https://i.example.com/chart', 'image', 800, 600),
                new EntryMedium('https://cdn.example/thumb.jpg', 'image'),
            ],
            $chart->getMedia(),
        );
    }

    public function testAPostSharingAPictureStaysALinkShare(): void
    {
        $post = $this->queuedPost(1, '<p>https://i.example.com/chart</p>');
        $post->setShare(Share::link(null));
        $this->entityManager->flush();

        $this->shareLinkCards->complete($this->claimable(), self::fiveFetches());
        $this->entityManager->refresh($post);

        self::assertSame(ShareKind::Link, $post->getShare()->kind);
        self::assertSame([], $post->getMedia());
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
            $failing = ShareLinkCardsWiring::build($closingEntityManager, $this->client, $this->clock);
            $failing->complete($this->claimable(), self::fiveFetches());
            self::fail('A failed flush must be rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Simulated flush failure.', $exception->getMessage());
        }

        self::assertSame([$post->requireId()], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    private static function page(string $method, string $url): MockResponse
    {
        if (str_contains($url, '/missing')) {
            return new MockResponse('Not found', ['http_code' => 404]);
        }
        if (str_contains($url, '/chart')) {
            return new MockResponse('PNG', ['http_code' => 200, 'response_headers' => ['content-type' => 'image/png']]);
        }
        if (str_contains($url, '/untitled')) {
            return new MockResponse(
                '<html lang="en"><head></head><body>No title.</body></html>',
                ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']],
            );
        }

        return new MockResponse(
            '<html lang="en"><head><meta property="og:title" content="Card for ' . $url . '"></head></html>',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']],
        );
    }

    public function testACallSpendsNoMoreFetchesThanItsBudget(): void
    {
        $oldest = $this->queuedPost(1, self::sharing(1, 1));
        $newer = $this->queuedPost(2, self::sharing(2, 1));

        $completed = $this->shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(1));

        self::assertSame(1, $completed);
        self::assertSame(1, $this->client->getRequestsCount());
        self::assertCount(1, $this->cardTitles($oldest));
        self::assertSame([$newer->requireId()], $this->enrichments()->pendingEntryIdsAmong([$newer->requireId()]));
    }

    public function testAPostWithMoreLinksThanTheBudgetIsStillCardedWhole(): void
    {
        $threeLinks = $this->queuedPost(1, self::sharing(1, 3));

        $completed = $this->shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(1));

        self::assertSame(1, $completed);
        self::assertSame(3, $this->client->getRequestsCount());
        self::assertCount(3, $this->cardTitles($threeLinks));
    }

    public function testACallStopsAtItsBudgetsDeadline(): void
    {
        $posts = array_map(
            fn (int $number): Entry => $this->queuedPost($number, self::sharing($number, 1)),
            range(1, 3),
        );
        $slowPages = new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->clock->sleep(9);

            return self::page($method, $url);
        });
        $shareLinkCards = ShareLinkCardsWiring::build($this->entityManager, $slowPages, $this->clock);

        $completed = $shareLinkCards->complete($this->claimable(), LinkCardBudgetModel::fetches(2));

        self::assertSame(1, $completed);
        self::assertSame(1, $slowPages->getRequestsCount());
        self::assertSame(
            [$posts[1]->requireId(), $posts[2]->requireId()],
            array_map(static fn (ShareEnrichment $row): int => $row->getEntry()->requireId(), $this->claimable()),
        );
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
    private function queuedPost(int $number, ?string $contentHtml): Entry
    {
        $entry = $this->entry($number, $contentHtml);
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
        $entry = $this->queuedPost($number, null);
        $entry->setTitle('A story');
        $entry->setShare(Share::link($target));
        $this->entityManager->flush();

        return $entry;
    }

    private function offsetEntryIdsFromRowIds(): void
    {
        $this->entityManager->persist($this->entry(0, null));
    }

    private function entry(int $number, ?string $contentHtml): Entry
    {
        $now = $this->clock->now();
        $entry = new Entry($this->feed, 'https://mastodon.example/@someone/' . $number, null, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);
        $entry->markTitleDerived();
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
