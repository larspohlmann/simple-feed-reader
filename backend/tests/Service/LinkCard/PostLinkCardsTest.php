<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Repository\LinkCardRepository;
use App\Service\LinkCard\PostLinkCards;
use App\Service\Reader\Exception\PageFetchException;
use App\Tests\DbTestCase;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\PostLinkCardsWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PostLinkCardsTest extends DbTestCase
{
    use ReloadsEntities;

    private const string AT_POST = 'at://did:plc:test/app.bsky.feed.post/3abc';

    private MockHttpClient $client;
    private RecordingLogger $logger;
    private PostLinkCards $postLinkCards;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new MockHttpClient(self::page(...));
        $this->logger = new RecordingLogger();
        $this->postLinkCards = PostLinkCardsWiring::build($this->entityManager, $this->client, $this->logger);
        $this->feed = new Feed('https://mastodon.example/@someone.rss');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testIngestFillsTheCardsAndLinksTheBareUrls(): void
    {
        $post = $this->post(
            'https://mastodon.example/@someone/1',
            '<p>Read https://example.com/a and <a href="https://example.com/b">https://example.com/b</a> '
            . '#<a href="https://mastodon.example/tags/x">x</a></p>',
        );

        $this->postLinkCards->forCreated([$post]);

        self::assertSame(2, $this->client->getRequestsCount());
        self::assertSame(
            ['Card for https://example.com/a', 'Card for https://example.com/b'],
            $this->cardTitles($post),
        );
        self::assertStringContainsString(
            'Read <a href="https://example.com/a"',
            (string) $this->reload($post)->getContentHtml(),
        );
    }

    public function testABodyWithNothingToLinkIsStoredUntouched(): void
    {
        $body = '<p><a href="https://example.com/a">https://example.com/a</a></p>';
        $post = $this->post('https://mastodon.example/@someone/1', $body);

        $this->postLinkCards->forCreated([$post]);

        self::assertSame(['Card for https://example.com/a'], $this->cardTitles($post));
        self::assertSame($body, $this->reload($post)->getContentHtml());
    }

    public function testTwelveSharedLinksAcrossThreePostsMakeTenFetches(): void
    {
        $posts = $this->threePostsOfFourLinks();

        $this->postLinkCards->forCreated($posts);

        self::assertSame(10, $this->client->getRequestsCount());
        self::assertCount(4, $this->cardTitles($posts[0]));
        self::assertCount(4, $this->cardTitles($posts[1]));
        self::assertSame([], $this->cardTitles($posts[2]));
    }

    public function testOpeningALeftoverPostFillsItAndASecondOpenFetchesNothing(): void
    {
        $posts = $this->threePostsOfFourLinks();
        $this->postLinkCards->forCreated($posts);

        $this->postLinkCards->forOpened($posts[2]->requireId());
        self::assertSame(14, $this->client->getRequestsCount());
        self::assertCount(4, $this->cardTitles($posts[2]));

        $this->postLinkCards->forOpened($posts[2]->requireId());
        self::assertSame(14, $this->client->getRequestsCount());
    }

    public function testPendingPostsShareFiveFetchesAndTheOneItRunsOutOnStaysUntried(): void
    {
        [$first, $second] = $this->threePostsOfFourLinks();

        $this->postLinkCards->forPending([$first, $second]);
        self::assertSame(5, $this->client->getRequestsCount());
        self::assertCount(4, $this->cardTitles($first));
        self::assertSame([], $this->cardTitles($second));

        $this->postLinkCards->forPending([$first, $second]);
        self::assertSame(9, $this->client->getRequestsCount());
        self::assertCount(4, $this->cardTitles($second));
    }

    public function testCompletingATriedPostAnArticleOrABlueskyPostFetchesNothing(): void
    {
        $tried = $this->post('https://mastodon.example/@someone/1', '<p>https://example.com/missing</p>');
        $this->postLinkCards->forCreated([$tried]);
        $article = $this->entry('https://example.org/article', '<p>See https://example.com/a</p>');
        $blueskyPost = $this->post(self::AT_POST, '<p>See https://example.com/a</p>');

        $this->postLinkCards->forPending([$tried, $article, $blueskyPost]);

        self::assertSame(1, $this->client->getRequestsCount());
    }

    public function testOpeningAPostIngestBuiltFetchesNothing(): void
    {
        $post = $this->post('https://mastodon.example/@someone/1', '<p>https://example.com/missing</p>');
        $this->postLinkCards->forCreated([$post]);

        $this->postLinkCards->forOpened($post->requireId());

        self::assertSame(1, $this->client->getRequestsCount());
    }

    public function testAMissingPageGivesNoCardButMarksThePostTried(): void
    {
        $post = $this->post(
            'https://mastodon.example/@someone/1',
            '<p>https://example.com/missing https://example.com/found</p>',
        );

        $this->postLinkCards->forCreated([$post]);
        $this->postLinkCards->forOpened($post->requireId());

        self::assertSame(2, $this->client->getRequestsCount());
        self::assertSame(['Card for https://example.com/found'], $this->cardTitles($post));
        self::assertSame([[
            'level' => 'warning',
            'message' => 'No link card for {url}',
            'context' => ['url' => 'https://example.com/missing', 'exception' => PageFetchException::class],
        ]], $this->logger->records);
    }

    public function testAPageWithoutATitleGivesNoCardAndLeavesNoGapInTheOrder(): void
    {
        $post = $this->post(
            'https://mastodon.example/@someone/1',
            '<p>https://example.com/untitled https://example.com/found</p>',
        );

        $this->postLinkCards->forCreated([$post]);

        self::assertSame(2, $this->client->getRequestsCount());
        self::assertSame(['Card for https://example.com/found'], $this->cardTitles($post));
        self::assertSame(
            [0],
            $this->entityManager->getConnection()->fetchFirstColumn(
                'SELECT position FROM link_card WHERE entry_id = ?',
                [$post->requireId()],
            ),
        );
    }

    public function testAFailedPassIsLoggedAndLeavesThePostForItsFirstOpen(): void
    {
        $post = $this->post('https://mastodon.example/@someone/1', '<p>https://example.com/missing</p>');
        $failingOnce = PostLinkCardsWiring::build(
            new FlushFailingEntityManager($this->entityManager),
            $this->client,
            $this->logger,
        );

        $failingOnce->forCreated([$post]);
        $failingOnce->forOpened($post->requireId());

        self::assertSame(2, $this->client->getRequestsCount());
        self::assertSame(
            ['warning', 'error', 'warning'],
            array_column($this->logger->records, 'level'),
        );
        self::assertSame('Link cards failed for entry {entry}', $this->logger->records[1]['message']);
        self::assertSame($post->requireId(), $this->logger->records[1]['context']['entry']);
    }

    public function testAFailureThatClosedTheEntityManagerIsRethrown(): void
    {
        $post = $this->post('https://mastodon.example/@someone/1', '<p>https://example.com/missing</p>');
        $closingEntityManager = new class ($this->entityManager) extends EntityManagerDecorator {
            public function flush(): void
            {
                $this->wrapped->close();

                throw new \RuntimeException('Simulated flush failure.');
            }
        };

        $this->expectExceptionMessage('Simulated flush failure.');

        PostLinkCardsWiring::build($closingEntityManager, $this->client, $this->logger)->forCreated([$post]);
    }

    public function testAPostWithoutABodyIsMarkedWithoutAFetch(): void
    {
        $post = $this->post('https://mastodon.example/@someone/1', null);

        $this->postLinkCards->forCreated([$post]);

        self::assertSame(0, $this->client->getRequestsCount());
        self::assertSame([], $this->logger->records);
        self::assertNull($this->reload($post)->getContentHtml());
    }

    public function testAnArticleAndABlueskyPostAreLeftAlone(): void
    {
        $article = $this->entry('https://example.org/article', '<p>See https://example.com/a</p>');
        $blueskyPost = $this->post(self::AT_POST, '<p>See https://example.com/a</p>');

        $this->postLinkCards->forCreated([$article, $blueskyPost]);
        $this->postLinkCards->forOpened($article->requireId());
        $this->postLinkCards->forOpened($blueskyPost->requireId());

        self::assertSame(0, $this->client->getRequestsCount());
        self::assertSame('<p>See https://example.com/a</p>', $this->reload($article)->getContentHtml());
        self::assertSame('<p>See https://example.com/a</p>', $this->reload($blueskyPost)->getContentHtml());
    }

    public function testOpeningAPostThatHasCardsFetchesNothing(): void
    {
        $post = $this->post('https://mastodon.example/@someone/1', '<p>https://example.com/a</p>');
        $this->linkCards()->replaceFor(
            $post,
            [new LinkCardContent('https://example.com/a', 'Stored', null, null, 'example.com')],
        );
        $this->entityManager->flush();

        $this->postLinkCards->forOpened($post->requireId());

        self::assertSame(0, $this->client->getRequestsCount());
        self::assertSame(['Stored'], $this->cardTitles($post));
    }

    public function testOpeningAnUnknownEntryFetchesNothing(): void
    {
        $this->postLinkCards->forOpened(999_999);

        self::assertSame(0, $this->client->getRequestsCount());
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

    /** @return list<Entry> */
    private function threePostsOfFourLinks(): array
    {
        return array_map(
            fn (int $post): Entry => $this->post(
                'https://mastodon.example/@someone/' . $post,
                '<p>' . implode(' ', array_map(
                    static fn (int $link): string => 'https://example.com/' . $post . '/' . $link,
                    range(1, 4),
                )) . '</p>',
            ),
            range(1, 3),
        );
    }

    private function post(string $guid, ?string $contentHtml): Entry
    {
        $entry = $this->entry($guid, $contentHtml);
        $entry->markTitleDerived();
        $this->entityManager->flush();

        return $entry;
    }

    private function entry(string $guid, ?string $contentHtml): Entry
    {
        $now = new \DateTimeImmutable('2026-10-10 12:00:00');
        $entry = new Entry($this->feed, $guid, $guid, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /** @return list<string> */
    private function cardTitles(Entry $entry): array
    {
        return array_map(
            static fn (LinkCard $card): string => $card->getContent()->title,
            $this->linkCards()->findByEntryIds([$entry->requireId()])[$entry->requireId()] ?? [],
        );
    }

    private function linkCards(): LinkCardRepository
    {
        $linkCards = $this->entityManager->getRepository(LinkCard::class);
        self::assertInstanceOf(LinkCardRepository::class, $linkCards);

        return $linkCards;
    }
}
