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
use App\Service\Curation\Trending\Model\TrendingStoryCardModel;
use App\Tests\DbTestCase;
use App\Tests\Support\TrendingFixtures;

final class TrendingStoryCardModelTest extends DbTestCase
{
    private TrendingFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = new TrendingFixtures($this->entityManager);
    }

    public function testAnArticleLeadsOverANewerPostWithAPicturedCard(): void
    {
        $article = $this->article();
        $post = $this->linkShare('ann', '2031-05-14 10:00:00', 'https://img.example/card.jpg');

        $card = self::cardOf([$post, $article]);

        self::assertSame($article->entry, $card->lead->entry);
        self::assertSame([$post->entry], self::entriesOf($card->lead->duplicates));
    }

    public function testWithoutAnArticleTheNewestLinkShareWithACardPictureLeads(): void
    {
        $pictured = $this->linkShare('ann', '2031-05-14 08:00:00', 'https://img.example/card.jpg');
        $plain = $this->linkShare('ben', '2031-05-14 09:00:00', null);
        $quote = $this->post('cat', '2031-05-14 10:00:00', ShareKind::Quote, 'https://img.example/quote.jpg');

        $card = self::cardOf([$quote, $plain, $pictured]);

        self::assertSame($pictured->entry, $card->lead->entry);
        self::assertSame([$quote->entry, $plain->entry], self::entriesOf($card->lead->duplicates));
    }

    public function testWithoutAPicturedCardTheNewestLinkShareLeads(): void
    {
        $older = $this->linkShare('ann', '2031-05-14 08:00:00', null);
        $newer = $this->cardlessLinkShare('ben', '2031-05-14 09:00:00');
        $quote = $this->post('cat', '2031-05-14 10:00:00', ShareKind::Quote, null);

        self::assertSame($newer->entry, self::cardOf([$older, $quote, $newer])->lead->entry);
    }

    public function testWithoutALinkShareTheNewestPostLeads(): void
    {
        $older = $this->post('ann', '2031-05-14 08:00:00', ShareKind::Quote, null);
        $newer = $this->post('ben', '2031-05-14 09:00:00', null, null);

        self::assertSame($newer->entry, self::cardOf([$older, $newer])->lead->entry);
    }

    /** @param non-empty-list<EntryListRow> $rows */
    private static function cardOf(array $rows): TrendingStoryCardModel
    {
        return TrendingStoryCardModel::fromRows(
            $rows,
            new RankedStoryModel(1, \count($rows), new \DateTimeImmutable('2031-05-14 10:00:00')),
            false,
        );
    }

    private function article(): EntryListRow
    {
        $entry = $this->fixtures->entry($this->fixtures->feed('outlet'), 'Article', '2031-05-14 08:00:00');

        return $this->rowOf($entry, []);
    }

    private function linkShare(string $poster, string $effectiveDate, ?string $cardImageUrl): EntryListRow
    {
        return $this->post($poster, $effectiveDate, ShareKind::Link, $cardImageUrl);
    }

    private function post(string $poster, string $effectiveDate, ?ShareKind $kind, ?string $cardImageUrl): EntryListRow
    {
        $feed = $this->fixtures->feed($poster, SocialNetwork::Mastodon);
        $entry = $this->fixtures->entry($feed, 'Post', $effectiveDate);
        $entry->setShareKind($kind);
        $card = new LinkCardContent('https://shared.example/story', 'Story', null, $cardImageUrl, 'Shared');

        return $this->rowOf($entry, [$card]);
    }

    private function cardlessLinkShare(string $poster, string $effectiveDate): EntryListRow
    {
        $feed = $this->fixtures->feed($poster, SocialNetwork::Mastodon);
        $entry = $this->fixtures->entry($feed, 'Post', $effectiveDate);
        $entry->setShareKind(ShareKind::Link);

        return $this->rowOf($entry, []);
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
