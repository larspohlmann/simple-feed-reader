<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
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
            ->windowEntries(new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')));
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
        $this->entityManager->persist(new LinkCard($post, 1, SharedLinks::of(
            $this->entityManager,
            new LinkCardContent(
                'https://shared.example/storm',
                'Storm floods the harbour',
                str_repeat('d', 2_100),
                null,
                'Shared',
            ),
        )));
        $this->entityManager->flush();

        $rows = iterator_to_array(
            (new TrendingEntryRepository($this->entityManager))->windowEntries(
                new \DateTimeImmutable('2031-05-13 12:00:00', new \DateTimeZone('UTC')),
            ),
            false,
        );

        self::assertSame('https://outlet.example/storm', $rows[0]->links->articleUrl);
        self::assertNull($rows[1]->links->articleUrl);
        self::assertEquals(
            new WindowLinkCardModel(
                'https://shared.example/storm',
                'Storm floods the harbour',
                str_repeat('d', 600),
                'Shared',
            ),
            $rows[1]->links->card,
        );
        self::assertNull($rows[2]->links->card);
        self::assertSame($bare->requireId(), $rows[2]->id);
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
