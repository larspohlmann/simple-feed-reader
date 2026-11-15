<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Model;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Enum\SocialNetwork;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnrichment;
use App\Repository\EntryListRowMarks;
use App\Repository\EntryListRowSubscription;
use App\Service\Curation\Trending\Model\RankedStoryModel;
use App\Service\Curation\Trending\Model\ShownRowModel;
use App\Service\Curation\Trending\Model\StoryArticleModel;
use App\Service\Curation\Trending\Model\TrendingStoryCardModel;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class TrendingStoryCardModelTest extends DbTestCase
{
    private const string ARTICLE = 'article-a';
    private const string OTHER_ARTICLE = 'article-b';

    private TrendingFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
    }

    public function testAnArticleLeadsOverANewerPostSharingAnotherArticleWithAPicturedCard(): void
    {
        $article = $this->outletRow('outlet', '2031-05-14 08:00:00', self::ARTICLE);
        $post = $this->linkShare('ann', '2031-05-14 10:00:00', 'https://img.example/card.jpg', self::OTHER_ARTICLE);

        $card = self::cardOf([$post, $article]);

        self::assertSame($article->row->entry, $card->lead()->entry);
        self::assertSame([$post->row->entry], self::entriesOf($card->lead()->duplicates));
    }

    public function testAPostSharingAFollowedArticleFoldsOntoItsRowAsAShare(): void
    {
        $article = $this->outletRow('outlet', '2031-05-14 08:00:00', self::ARTICLE);
        $post = $this->linkShare('ann', '2031-05-14 10:00:00', null, self::ARTICLE);

        $card = self::cardOf([$post, $article]);

        self::assertSame($article->row->entry, $card->lead()->entry);
        self::assertSame([], $card->lead()->duplicates);
        self::assertSame([$post->row->entry], self::sharesOf($card->articles->lead));
        self::assertSame(1, $card->articles->shareCount());
    }

    public function testTwoOutletsCarryingOneArticleAreOneRow(): void
    {
        $older = $this->outletRow('alpha', '2031-05-14 08:00:00', self::ARTICLE);
        $newer = $this->outletRow('beta', '2031-05-14 09:00:00', self::ARTICLE);

        $card = self::cardOf([$older, $newer]);

        self::assertSame($newer->row->entry, $card->lead()->entry);
        self::assertSame([], $card->lead()->duplicates);
    }

    public function testWithoutAFollowedArticleTheMostSharedArticleLeadsAsItsNewestPicturedShare(): void
    {
        $pictured = $this->linkShare('ann', '2031-05-14 08:00:00', 'https://img.example/card.jpg', self::ARTICLE);
        $plain = $this->linkShare('ben', '2031-05-14 09:00:00', null, self::ARTICLE);
        $newerOther = $this->linkShare('cat', '2031-05-14 10:00:00', 'https://img.example/b.jpg', self::OTHER_ARTICLE);

        $card = self::cardOf([$newerOther, $plain, $pictured]);

        self::assertSame($pictured->row->entry, $card->lead()->entry);
        self::assertSame([$plain->row->entry, $pictured->row->entry], self::sharesOf($card->articles->lead));
        self::assertSame([$newerOther->row->entry], self::entriesOf($card->lead()->duplicates));
        self::assertSame(3, $card->articles->shareCount());
    }

    public function testEquallySharedArticlesAreLedByTheNewest(): void
    {
        $older = $this->linkShare('ann', '2031-05-14 08:00:00', null, self::ARTICLE);
        $newer = $this->linkShare('ben', '2031-05-14 09:00:00', null, self::OTHER_ARTICLE);

        self::assertSame($newer->row->entry, self::cardOf([$older, $newer])->lead()->entry);
    }

    public function testWithoutAPicturedCardTheNewestLinkShareRepresentsItsArticle(): void
    {
        $older = $this->linkShare('ann', '2031-05-14 08:00:00', null, self::ARTICLE);
        $newer = $this->cardlessLinkShare('ben', '2031-05-14 09:00:00');
        $quote = $this->post('cat', '2031-05-14 10:00:00', ShareKind::Quote, null);

        self::assertSame($newer->row->entry, self::cardOf([$older, $quote, $newer])->lead()->entry);
    }

    public function testWithoutALinkShareTheNewestPostRepresentsItsArticle(): void
    {
        $older = $this->post('ann', '2031-05-14 08:00:00', ShareKind::Quote, null);
        $newer = $this->post('ben', '2031-05-14 09:00:00', null, null);

        self::assertSame($newer->row->entry, self::cardOf([$older, $newer])->lead()->entry);
    }

    public function testAPostsCopiesInSeveralTimelinesAreOneShare(): void
    {
        $article = $this->outletRow('outlet', '2031-05-14 08:00:00', self::ARTICLE);
        $home = $this->timelineCopy('home', '2031-05-14 09:00:00');
        $discover = $this->timelineCopy('discover', '2031-05-14 09:30:00');

        $card = self::cardOf([$article, $home, $discover]);

        self::assertSame([$discover->row->entry], self::sharesOf($card->articles->lead));
        self::assertSame(1, $card->articles->shareCount());
    }

    /** @param non-empty-list<ShownRowModel> $rows */
    private static function cardOf(array $rows): TrendingStoryCardModel
    {
        return TrendingStoryCardModel::fromRows(
            $rows,
            new RankedStoryModel(1, \count($rows), new \DateTimeImmutable('2031-05-14 10:00:00')),
            false,
        );
    }

    private function outletRow(string $outlet, string $effectiveDate, string $articleKey): ShownRowModel
    {
        $entry = $this->fixtures->entry($this->fixtures->feed($outlet), 'Article', $effectiveDate);

        return new ShownRowModel($this->rowOf($entry, []), $articleKey);
    }

    private function linkShare(
        string $poster,
        string $effectiveDate,
        ?string $cardImageUrl,
        string $articleKey,
    ): ShownRowModel {
        return new ShownRowModel(
            $this->post($poster, $effectiveDate, ShareKind::Link, $cardImageUrl)->row,
            $articleKey,
        );
    }

    private function post(string $poster, string $effectiveDate, ?ShareKind $kind, ?string $cardImageUrl): ShownRowModel
    {
        $feed = $this->fixtures->feed($poster, SocialNetwork::Mastodon);
        $entry = $this->fixtures->entry($feed, 'Post', $effectiveDate);
        $entry->setShareKind($kind);
        $card = new LinkCardContent('https://shared.example/story', 'Story', null, $cardImageUrl, 'Shared');

        return new ShownRowModel($this->rowOf($entry, [$card]), self::ARTICLE);
    }

    private function cardlessLinkShare(string $poster, string $effectiveDate): ShownRowModel
    {
        $feed = $this->fixtures->feed($poster, SocialNetwork::Mastodon);
        $entry = $this->fixtures->entry($feed, 'Post', $effectiveDate);
        $entry->setShareKind(ShareKind::Link);

        return new ShownRowModel($this->rowOf($entry, []), self::ARTICLE);
    }

    private function timelineCopy(string $timeline, string $effectiveDate): ShownRowModel
    {
        $feed = $this->fixtures->feed($timeline, SocialNetwork::Mastodon);
        $entry = $this->fixtures->article($feed, 'Post', $effectiveDate, 'https://social.example/@poster/1');
        $entry->setShareKind(ShareKind::Link);

        return new ShownRowModel($this->rowOf($entry, []), self::ARTICLE);
    }

    /** @param list<LinkCardContent> $linkCards */
    private function rowOf(Entry $entry, array $linkCards): EntryListRow
    {
        $this->fixtures->flush();

        return new EntryListRow(
            $entry,
            new EntryListRowSubscription($entry->getFeed()->requireId(), 'Feed'),
            new EntryListRowMarks(isHidden: false, isFavorite: false, isKept: false, viewedAt: null, sharedAt: null),
            null,
            [],
            new EntryListRowEnrichment(linkCards: $linkCards),
        );
    }

    /** @return list<Entry> */
    private static function sharesOf(StoryArticleModel $article): array
    {
        return self::entriesOf($article->shares);
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<Entry>
     */
    private static function entriesOf(array $rows): array
    {
        return array_map(static fn (EntryListRow $row): Entry => $row->entry, $rows);
    }
}
