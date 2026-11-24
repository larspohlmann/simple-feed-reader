<?php

declare(strict_types=1);

namespace App\Tests\Service\Library\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Library\Opml\Exception\InvalidOpmlException;
use App\Service\Library\Opml\Model\OpmlFeedState;
use App\Service\Library\Opml\Model\OpmlPreviewModel;
use App\Service\Library\Opml\OpmlPreviewer;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class OpmlPreviewerTest extends DbTestCase
{
    use SeedsUsers;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->user('opml-previewer@example.test');
    }

    public function testEveryFeedGetsAState(): void
    {
        $this->subscribe('https://old.example/feed');

        $preview = $this->preview(
            $this->opml(
                self::outline('https://old.example/feed')
                . self::outline('https://new.example/feed')
                . self::outline('ftp://bad.example/feed')
                . self::outline('https://scraped.example/page', ' sourceFormat="scraped"'),
            ),
        );

        self::assertSame(
            [
                'https://old.example/feed' => OpmlFeedState::Subscribed,
                'https://new.example/feed' => OpmlFeedState::New,
                'ftp://bad.example/feed' => OpmlFeedState::InvalidUrl,
                'https://scraped.example/page' => OpmlFeedState::Scraped,
            ],
            $preview->states,
        );
    }

    public function testASubscribedScrapedFeedIsSubscribed(): void
    {
        $this->subscribe('https://scraped.example/page');

        $preview = $this->preview(
            $this->opml(self::outline('https://scraped.example/page', ' sourceFormat="scraped"')),
        );

        self::assertSame(OpmlFeedState::Subscribed, $preview->states['https://scraped.example/page']);
    }

    public function testAFeedInsideAFolderGetsAState(): void
    {
        $preview = $this->preview(
            $this->opml(
                '<outline text="A"><outline text="B">' . self::outline('https://deep.example/feed')
                . '</outline></outline>',
            ),
        );

        self::assertSame(OpmlFeedState::New, $preview->states['https://deep.example/feed']);
    }

    public function testFreeSlotsAreTheCapLessTheSubscriptions(): void
    {
        $this->owner->setMaxSubscriptions(3);
        $this->subscribe('https://old.example/feed');

        self::assertSame(2, $this->preview($this->opml(''))->freeSlots);
    }

    public function testFreeSlotsNeverGoBelowZero(): void
    {
        $this->owner->setMaxSubscriptions(1);
        $this->subscribe('https://one.example/feed');
        $this->subscribe('https://two.example/feed');

        self::assertSame(0, $this->preview($this->opml(''))->freeSlots);
    }

    public function testTheParentTagReachesTheDestinations(): void
    {
        $preview = $this->preview($this->opml(''), 'From Feedly');

        self::assertSame(['From Feedly'], $preview->root->destination);
    }

    public function testAPreviewWritesNothing(): void
    {
        $before = $this->rowCounts();

        $this->preview(
            $this->opml('<outline text="A">' . self::outline('https://new.example/feed') . '</outline>'),
            'From Feedly',
        );
        $this->entityManager->flush();

        self::assertSame($before, $this->rowCounts());
    }

    public function testAnUnreadableFileIsRefused(): void
    {
        $this->expectException(InvalidOpmlException::class);

        $this->preview('not xml');
    }

    private function preview(string $opml, ?string $parentTagName = null): OpmlPreviewModel
    {
        return $this->previewer()->preview($this->owner, $opml, $parentTagName);
    }

    private function previewer(): OpmlPreviewer
    {
        $previewer = self::getContainer()->get(OpmlPreviewer::class);
        self::assertInstanceOf(OpmlPreviewer::class, $previewer);

        return $previewer;
    }

    private function subscribe(string $url): void
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $subscribedAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $this->entityManager->persist(new Subscription($this->owner, $feed, $subscribedAt));
        $this->entityManager->flush();
    }

    /** @return array{feeds: int, subscriptions: int, tags: int} */
    private function rowCounts(): array
    {
        return [
            'feeds' => $this->entityManager->getRepository(Feed::class)->count([]),
            'subscriptions' => $this->entityManager->getRepository(Subscription::class)->count([]),
            'tags' => $this->entityManager->getRepository(Tag::class)->count([]),
        ];
    }

    private function opml(string $bodyInner): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<opml version="2.0"><head><title>t</title></head><body>' . $bodyInner . '</body></opml>';
    }

    private static function outline(string $url, string $attributes = ''): string
    {
        return '<outline type="rss" text="Feed" xmlUrl="' . $url . '"' . $attributes . '/>';
    }
}
