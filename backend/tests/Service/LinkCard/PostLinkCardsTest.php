<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use App\Repository\PostEnrichmentRepository;
use App\Service\LinkCard\PostLinkCards;
use App\Service\Reader\Exception\PageFetchException;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\PostLinkCardsWiring;
use App\Tests\Support\RecordingLogger;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PostLinkCardsTest extends DbTestCase
{
    private MockClock $clock;
    private MockHttpClient $client;
    private RecordingLogger $logger;
    private PostLinkCards $postLinkCards;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->client = new MockHttpClient(self::page(...));
        $this->logger = new RecordingLogger();
        $this->postLinkCards = PostLinkCardsWiring::build(
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

        $this->postLinkCards->complete($this->claimable());
        $this->postLinkCards->complete([$this->rowOf($post)]);

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

        $this->postLinkCards->complete($this->claimable());
        self::assertSame(3, $this->client->getRequestsCount());
        self::assertCount(3, $this->cardTitles($oldest));
        self::assertSame([], $this->cardTitles($middle));

        $this->postLinkCards->complete($this->claimable());
        self::assertSame(8, $this->client->getRequestsCount());
        self::assertCount(3, $this->cardTitles($middle));
        self::assertCount(2, $this->cardTitles($newest));
    }

    public function testAFiveLinkPostGetsEveryCardAndTheNextPostWaitsForTheNextCall(): void
    {
        $fiveLinks = $this->queuedPost(1, self::sharing(1, 5));
        $twoLinks = $this->queuedPost(2, self::sharing(2, 2));

        $this->postLinkCards->complete($this->claimable());
        self::assertSame(5, $this->client->getRequestsCount());
        self::assertCount(5, $this->cardTitles($fiveLinks));
        self::assertSame([], $this->cardTitles($twoLinks));

        $this->postLinkCards->complete($this->claimable());
        self::assertSame(7, $this->client->getRequestsCount());
        self::assertCount(2, $this->cardTitles($twoLinks));
        self::assertSame([], $this->claimable());
    }

    public function testARowAnotherWorkerHoldsIsLeftToIt(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $this->enrichments()->claim([$this->rowOf($post)], $this->clock->now());

        $this->postLinkCards->complete([$this->rowOf($post)]);

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
        $postLinkCards = PostLinkCardsWiring::build($this->entityManager, $slowPages, $this->clock);

        $postLinkCards->complete($this->claimable());

        self::assertSame(3, $slowPages->getRequestsCount());
        self::assertCount(1, $this->cardTitles($posts[2]));
        self::assertSame(
            [$posts[3]->requireId(), $posts[4]->requireId()],
            array_map(static fn (PostEnrichment $row): int => $row->getEntry()->requireId(), $this->claimable()),
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

        PostLinkCardsWiring::build($this->entityManager, $takeOver, $this->clock)->complete($this->claimable());

        self::assertSame([], $this->cardTitles($post));
        self::assertSame([$post->requireId()], $this->enrichments()->pendingEntryIdsAmong([$post->requireId()]));
    }

    public function testAPostThatAlreadyHoldsCardsGetsNoSecondSet(): void
    {
        $post = $this->queuedPost(1, self::sharing(1, 1));
        $this->entityManager->persist(new LinkCard($post, 0, new LinkCardContent(
            'https://example.com/1/1',
            'Stored before',
            null,
            null,
            'example.com',
        )));
        $this->entityManager->flush();

        $this->postLinkCards->complete($this->claimable());

        self::assertSame(['Stored before'], $this->cardTitles($post));
        self::assertSame([], $this->claimable());
    }

    public function testACardedQuoteLeavesTheBodyAndTheSummaryIsDerivedAgain(): void
    {
        $post = $this->queuedPost(1, '<p>RE: <a href="https://q.example/@a/1">https://q.example/@a/1</a></p>'
            . '<p>Reposting for the morning crew</p>');
        $post->setSummary('RE: https://q.example/@a/1 Reposting for the morning crew');
        $this->entityManager->flush();

        $this->postLinkCards->complete($this->claimable());
        $this->entityManager->refresh($post);

        self::assertSame(['Card for https://q.example/@a/1'], $this->cardTitles($post));
        self::assertSame('<p>Reposting for the morning crew</p>', $post->getContentHtml());
        self::assertSame('Reposting for the morning crew', $post->getSummary());
    }

    public function testAQuoteWhosePageFailsKeepsItsLine(): void
    {
        $body = '<p>RE: <a href="https://q.example/missing">https://q.example/missing</a></p><p>Look.</p>';
        $post = $this->queuedPost(1, $body);

        $this->postLinkCards->complete($this->claimable());
        $this->entityManager->refresh($post);

        self::assertSame([], $this->cardTitles($post));
        self::assertSame($body, $post->getContentHtml());
    }

    public function testAMissingPageGivesNoCardAndTheRowIsStillDone(): void
    {
        $post = $this->queuedPost(1, '<p>https://example.com/missing https://example.com/found</p>');

        $this->postLinkCards->complete($this->claimable());

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

        $this->postLinkCards->complete($this->claimable());

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

        $this->postLinkCards->complete($this->claimable());

        self::assertSame(0, $this->client->getRequestsCount());
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
            $failing = PostLinkCardsWiring::build($closingEntityManager, $this->client, $this->clock);
            $failing->complete($this->claimable());
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
        $now = $this->clock->now();
        $entry = new Entry($this->feed, 'https://mastodon.example/@someone/' . $number, null, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);
        $entry->markTitleDerived();
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);
        $this->entityManager->persist(new PostEnrichment(
            $entry,
            PostEnrichmentKind::LinkCards,
            $now->modify(sprintf('-%d minutes', 10 - $number)),
        ));
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<PostEnrichment> */
    private function claimable(): array
    {
        return $this->enrichments()->findClaimableFor(
            $this->entityManager->getRepository(Entry::class)->findBy(['feed' => $this->feed]),
            $this->clock->now(),
        );
    }

    private function rowOf(Entry $entry): PostEnrichment
    {
        $row = $this->enrichments()->findOneBy(['entry' => $entry]);
        self::assertInstanceOf(PostEnrichment::class, $row);

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

    private function enrichments(): PostEnrichmentRepository
    {
        return PostEnrichers::enrichments($this->entityManager);
    }
}
