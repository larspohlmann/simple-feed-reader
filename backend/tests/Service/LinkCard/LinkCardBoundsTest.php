<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\LinkCardContent;
use App\Entity\PostEnrichment;
use App\Enum\PostEnrichmentKind;
use App\Service\Bluesky\AppViewClient;
use App\Service\Fetch\HostThrottle;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\PostLinkCardsWiring;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Only MySQL enforces the column lengths, so these pin on the MySQL leg what SQLite would let through. */
final class LinkCardBoundsTest extends DbTestCase
{
    private const string AT_POST = 'at://did:plc:test/app.bsky.feed.post/3bounds';

    private MockClock $clock;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-11 12:00:00', 'UTC');
        $this->feed = new Feed('https://f.test/' . uniqid('', true));
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testAnAppViewCardWithASixHundredCharacterTitleIsStoredClamped(): void
    {
        $post = $this->post(self::AT_POST, '<p>Read this.</p>');
        $this->entityManager->persist(new PostEnrichment($post, PostEnrichmentKind::AppView, $this->clock->now()));
        $this->entityManager->flush();
        $appView = new FakeAppView();
        $appView->knowsPost(['uri' => self::AT_POST, 'embed' => [
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://example.com/story',
                'title' => str_repeat('t', 600),
                'description' => str_repeat('word ', 400),
            ],
        ]]);
        $enricher = PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient($appView, new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock)),
            new NullLogger(),
        );

        $enricher->complete(PostEnrichers::enrichments($this->entityManager)->findBy(['entry' => $post]));

        [$card] = $this->storedCards($post);
        self::assertSame(str_repeat('t', 500), $card->title);
        self::assertLessThanOrEqual(300, mb_strlen((string) $card->description));
    }

    public function testAPostSharingATwoThousandOneHundredCharacterHrefGetsCardsForTheRest(): void
    {
        $longHref = 'https://example.com/' . str_repeat('a', 2080);
        $post = $this->post(
            'https://mastodon.example/@a/1',
            '<p><a href="' . $longHref . '">' . $longHref . '</a> and https://example.com/story</p>',
        );
        PostEnrichers::queue($this->entityManager, $this->clock)->queue([$post]);
        $this->entityManager->flush();
        $pages = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
            '<html lang="en"><head><meta property="og:title" content="Card for ' . $url . '"></head></html>',
            ['http_code' => 200, 'response_headers' => ['content-type' => 'text/html']],
        ));

        PostLinkCardsWiring::build($this->entityManager, $pages, $this->clock)->complete(
            PostEnrichers::enrichments($this->entityManager)->findBy(['entry' => $post]),
        );

        self::assertSame(1, $pages->getRequestsCount());
        self::assertSame(['https://example.com/story'], array_column($this->storedCards($post), 'url'));
    }

    private function post(string $guid, string $contentHtml): Entry
    {
        $now = $this->clock->now();
        $entry = new Entry($this->feed, $guid, null, 'Post', $now, $now);
        $entry->setContentHtml($contentHtml);
        $entry->markTitleDerived();
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);

        return $entry;
    }

    /** @return list<LinkCardContent> */
    private function storedCards(Entry $entry): array
    {
        $this->entityManager->clear();

        $cardsByEntryId = PostEnrichers::linkCards($this->entityManager)->contentsByEntryIds([$entry->requireId()]);

        return $cardsByEntryId[$entry->requireId()] ?? [];
    }
}
