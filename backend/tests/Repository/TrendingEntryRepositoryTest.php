<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\LinkCard;
use App\Entity\LinkCardContent;
use App\Entity\ThreadPost;
use App\Entity\ThreadPostContent;
use App\Enum\FeedLanguage;
use App\Enum\SocialNetwork;
use App\Repository\TrendingEntryRepository;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Service\Curation\Trending\Model\WindowLinkCardModel;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;
use App\Tests\Support\SharedLinks;
use App\Entity\SharedLink;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Content\LinkCard\SharedLinkWriter;
use App\Tests\Support\PostEnrichers;

final class TrendingEntryRepositoryTest extends DbTestCase
{
    public function testStreamsTheWindowsEntriesInIdOrderWithTheirFirstRootLinkCard(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('stream');
        $tooOld = $fixtures->entry($feed, 'Too old', '2031-05-13 11:59:59');
        $first = $fixtures->article($feed, 'First', '2031-05-13 12:00:00', 'https://news.example.com/first');
        $second = $fixtures->entry($feed, 'Second', '2031-05-14 09:00:00');
        $second->recordText($second->getText()->withSummary('<p>Body</p>'));
        $fixtures->flush();
        $this->entityManager->persist(new LinkCard($second, 1, $this->link('https://later.example.com/b')));
        $this->entityManager->persist(new LinkCard($second, 0, $this->link('https://shared.example.com/a')));
        $this->entityManager->flush();

        $rows = iterator_to_array(
            (new TrendingEntryRepository($this->entityManager))->windowEntries(
                $this->ownerId(),
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            ),
            false,
        );

        self::assertSame(
            [$first->requireId(), $second->requireId()],
            array_map(static fn (WindowEntryModel $row): int => $row->id, $rows),
        );
        self::assertNotContains(
            $tooOld->requireId(),
            array_map(static fn (WindowEntryModel $row): int => $row->id, $rows),
        );
        self::assertSame($feed->requireId(), $rows[0]->feedId);
        self::assertNull($rows[0]->language);
        self::assertSame($first->getUrlHash(), $rows[0]->links->urlHash);
        self::assertNull($rows[0]->links->card);
        self::assertSame('https://shared.example.com/a', $rows[1]->links->card?->url);
        self::assertSame('<p>Body</p>', $rows[1]->summary);
        self::assertSame('2031-05-14 09:00:00', $rows[1]->effectiveDate->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $rows[1]->effectiveDate->getTimezone()->getName());
    }

    public function testStreamsMoreThanOneChunkWithoutRepeatingOrSkipping(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('chunks');
        $expected = [];
        for ($index = 0; $index <= 1_000; ++$index) {
            $expected[] = $fixtures->entry($feed, 'In ' . $index, '2031-05-14 09:00:00');
            if ($index === 500) {
                $fixtures->flush();
                $fixtures->entry($feed, 'Out', '2031-05-01 09:00:00');
                $fixtures->flush();
            }
        }
        $fixtures->flush();
        $expectedIds = array_map(static fn (Entry $entry): int => $entry->requireId(), $expected);

        $streamedIds = [];
        $rows = (new TrendingEntryRepository($this->entityManager))
            ->windowEntries(
                $this->ownerId(),
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            );
        foreach ($rows as $row) {
            $streamedIds[] = $row->id;
            if (\count($streamedIds) > 3_000) {
                break;
            }
        }

        self::assertSame($expectedIds, $streamedIds);
    }

    public function testAWindowEntryCarriesItsFeedsLanguage(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('french');
        $feed->recordTraits($feed->getTraits()->withLanguage(FeedLanguage::French));
        $fixtures->entry($feed, 'Élections', '2031-05-14 09:00:00');
        $fixtures->flush();

        $rows = iterator_to_array(
            (new TrendingEntryRepository($this->entityManager))->windowEntries(
                $this->ownerId(),
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            ),
            false,
        );

        self::assertSame(FeedLanguage::French, $rows[0]->language);
    }

    public function testAWindowEntryReadsItsOwnLanguageBeforeItsFeeds(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('timeline');
        $feed->recordTraits($feed->getTraits()->withLanguage(FeedLanguage::French));
        $own = $fixtures->entry($feed, 'Own', '2031-05-14 09:00:00');
        $own->recordText($own->getText()->withLanguage(FeedLanguage::German));
        $fixtures->entry($feed, 'Inherited', '2031-05-14 10:00:00');
        $fixtures->flush();

        $rows = iterator_to_array(
            (new TrendingEntryRepository($this->entityManager))->windowEntries(
                $this->ownerId(),
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            ),
            false,
        );

        self::assertSame(
            [FeedLanguage::German, FeedLanguage::French],
            array_map(static fn (WindowEntryModel $row): ?FeedLanguage => $row->language, $rows),
        );
    }

    public function testAnArticleEntryCarriesItsUrlAndAPostItsFirstTopLevelCardsText(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $outlet = $fixtures->feed('outlet');
        $timeline = $fixtures->feed('poster', SocialNetwork::Mastodon);
        $fixtures->article($outlet, 'Storm floods the harbour', '2031-05-14 08:00:00', 'https://outlet.example/storm');
        $post = $fixtures->entry($timeline, 'Look at this', '2031-05-14 09:00:00');
        $bare = $fixtures->entry($timeline, 'No card here', '2031-05-14 10:00:00');
        $fixtures->flush();
        $thread = new ThreadPost($post, 0, new ThreadPostContent(null, null, '<p>Thread reply</p>', []));
        $this->entityManager->persist($thread);
        $this->entityManager->persist(new LinkCard($post, 0, $this->link('https://thread.example/reply'), $thread));
        $this->entityManager->persist(new LinkCard($post, 2, $this->link('https://later.example/second')));
        $storm = $this->sharedLinks()->linkFor(
            new LinkCardContent(
                'https://shared.example/storm',
                'Storm floods the harbour',
                str_repeat('d', 2_100),
                null,
                'Shared',
            ),
            FeedLanguage::French,
        );
        $this->entityManager->persist(new LinkCard($post, 1, $storm));
        $this->entityManager->flush();

        $rows = iterator_to_array(
            (new TrendingEntryRepository($this->entityManager))->windowEntries(
                $this->ownerId(),
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            ),
            false,
        );

        self::assertSame('https://outlet.example/storm', $rows[0]->links->articleUrl);
        self::assertNull($rows[1]->links->articleUrl);
        self::assertEquals(
            new WindowLinkCardModel(
                $storm->requireId(),
                (string) (new UrlNormalizer())->hash('https://shared.example/storm'),
                'https://shared.example/storm',
                'Storm floods the harbour',
                str_repeat('d', 600),
                'Shared',
                FeedLanguage::French,
            ),
            $rows[1]->links->card,
        );
        self::assertNull($rows[2]->links->card);
        self::assertSame($bare->requireId(), $rows[2]->id);
    }

    public function testACardReadsItsResolvedUrl(): void
    {
        $short = $this->cardedPost('https://n.pr/x');
        $this->settle($short, "'resolved'", 'https://www.npr.org/2026/11/09/a');

        $card = $this->onlyCard();

        self::assertSame('https://www.npr.org/2026/11/09/a', $card->url);
        self::assertSame((new UrlNormalizer())->hash('https://www.npr.org/2026/11/09/a'), $card->urlHash);
        self::assertSame('npr.org', $card->site);
        self::assertSame($short->requireId(), $card->sharedLinkId);
    }

    public function testAnUnresolvedCardReadsItsPostedUrl(): void
    {
        $short = $this->cardedPost('https://n.pr/x');
        $this->settle($short, "'unresolved'", null);

        $card = $this->onlyCard();

        self::assertSame('https://n.pr/x', $card->url);
        self::assertSame($short->getUrlHash(), $card->urlHash);
    }

    private function cardedPost(string $cardUrl): SharedLink
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $post = $fixtures->entry($fixtures->feed('poster', SocialNetwork::Bluesky), 'Look', '2031-05-14 09:00:00');
        $fixtures->flush();
        $link = $this->link($cardUrl);
        $this->entityManager->persist(new LinkCard($post, 0, $link));
        $this->entityManager->flush();

        return $link;
    }

    private function settle(SharedLink $link, string $status, ?string $resolvedUrl): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE shared_link_resolution SET status = ' . $status . ', resolved_url = ?, resolved_url_hash = ?'
            . ' WHERE shared_link_id = ?',
            [$resolvedUrl, (new UrlNormalizer())->hash($resolvedUrl), $link->requireId()],
        );
    }

    private function onlyCard(): WindowLinkCardModel
    {
        $rows = iterator_to_array(
            (new TrendingEntryRepository($this->entityManager))->windowEntries(
                $this->ownerId(),
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            ),
            false,
        );
        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]->links->card);

        return $rows[0]->links->card;
    }

    public function testWindowEntriesAreOnlyTheUsersIncludedFeeds(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $included = $fixtures->feed('included');
        $switchedOff = $fixtures->feed('switched-off');
        $stranger = $fixtures->feedNobodyFollows('stranger');
        $expected = $fixtures->entry($included, 'Included', '2031-05-14 09:00:00');
        $fixtures->entry($switchedOff, 'Switched off', '2031-05-14 09:00:00');
        $fixtures->entry($stranger, 'Stranger', '2031-05-14 09:00:00');
        $fixtures->flush();
        $this->subscriptionTo($switchedOff)->changeInclusions(new InclusionChange(trending: false));
        $fixtures->flush();

        self::assertSame([$expected->requireId()], $this->windowEntryIds());
    }

    public function testWindowEntriesLeaveOutAFeedUnderASwitchedOffTag(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $feed = $fixtures->feed('tagged');
        $fixtures->entry($feed, 'Tagged', '2031-05-14 09:00:00');
        $fixtures->flush();
        $tag = new Tag($fixtures->owner(), 'Muted');
        $this->entityManager->persist($tag);
        $subscription = $this->subscriptionTo($feed);
        $subscription->addTag($tag);
        $tag->changeInclusions(new InclusionChange(trending: false));
        $fixtures->flush();

        self::assertSame([], $this->windowEntryIds());
    }

    public function testHasEntriesCreatedAfter(): void
    {
        $fixtures = new TrendingFixtures($this->entityManager);
        $fixtures->entry($fixtures->feed('included'), 'Included', '2031-05-14 09:00:00');
        $fixtures->entry($fixtures->feedNobodyFollows('stranger'), 'Newer stranger', '2031-05-14 10:00:00');
        $fixtures->flush();
        $created = new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC'));
        $repository = new TrendingEntryRepository($this->entityManager);

        self::assertTrue($repository->hasEntriesCreatedAfter($this->ownerId(), $created->modify('-1 second')));
        self::assertFalse($repository->hasEntriesCreatedAfter($this->ownerId(), $created));
    }

    private function ownerId(): int
    {
        return (new TrendingFixtures($this->entityManager))->owner()->requireId();
    }

    /** @return list<int> */
    private function windowEntryIds(): array
    {
        return array_map(
            static fn (WindowEntryModel $row): int => $row->id,
            iterator_to_array(
                (new TrendingEntryRepository($this->entityManager))->windowEntries(
                    $this->ownerId(),
                    new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
                ),
                false,
            ),
        );
    }

    private function subscriptionTo(Feed $feed): Subscription
    {
        $subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);

        return $subscription;
    }

    private function sharedLinks(): SharedLinkWriter
    {
        return PostEnrichers::sharedLinkWriter($this->entityManager);
    }

    private function link(string $url): SharedLink
    {
        return SharedLinks::of($this->entityManager, self::card($url));
    }

    private static function card(string $url): LinkCardContent
    {
        return new LinkCardContent($url, 'Card', null, null, 'example.com');
    }
}
