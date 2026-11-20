<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Pass;

use App\Enum\FeedLanguage;
use App\Enum\TrendingWindow;
use App\Service\Curation\Trending\Model\EntryLinksModel;
use App\Service\Curation\Trending\Model\WindowEntryModel;
use App\Tests\Support\TrendingRuns;
use PHPUnit\Framework\TestCase;

final class TrendingRunTest extends TestCase
{
    private const float TWICE = 6.2159;
    private const string CLIPPED_TITLE = 'Storm surge hits Hamb…';
    private const string SUMMARY = '<p>Storm surge hits Hamburg harbour</p>';

    public function testADerivedTitleRepeatedAtTheStartOfTheBodyAddsNothingFromTheRepeat(): void
    {
        self::assertEqualsWithDelta(1.5 * self::TWICE, $this->weightSharedWith(
            new WindowEntryModel(
                2,
                20,
                null,
                self::CLIPPED_TITLE,
                true,
                self::SUMMARY,
                self::later(),
                new EntryLinksModel(null, null, null),
            ),
        ), 0.0001);
    }

    public function testAnArticleWhoseSummaryStartsWithItsTitleKeepsThoseBodyStems(): void
    {
        self::assertEqualsWithDelta(2.0 * self::TWICE, $this->weightSharedWith(
            new WindowEntryModel(
                2,
                20,
                null,
                self::CLIPPED_TITLE,
                false,
                self::SUMMARY,
                self::later(),
                new EntryLinksModel(null, null, null),
            ),
        ), 0.0001);
    }

    public function testAMemberKnowsItsLanguageAndWhetherAnOutletPublishedIt(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $moment = new \DateTimeImmutable('2031-05-14 09:00:00', new \DateTimeZone('UTC'));
        $run->add(
            new WindowEntryModel(
                1,
                10,
                FeedLanguage::German,
                'Hafenstreik in Hamburg',
                false,
                null,
                $moment,
                new EntryLinksModel('article-hash', 'https://outlet.example/hafenstreik', null),
            ),
            ['article-hash'],
            null,
        );
        $run->add(
            new WindowEntryModel(
                2,
                20,
                null,
                'Hafenstreik',
                false,
                null,
                $moment,
                new EntryLinksModel('post-hash', null, null),
            ),
            ['post-hash'],
            null,
        );

        $article = $run->readMember(1);
        $post = $run->readMember(2);

        self::assertSame(FeedLanguage::German, $article->language);
        self::assertTrue($article->fromAnOutlet);
        self::assertSame('article-hash', $article->urlHash);
        self::assertNull($post->language);
        self::assertFalse($post->fromAnOutlet);
        self::assertSame('post-hash', $post->urlHash);
    }

    public function testCopiesGroupIntoOneStory(): void
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        foreach ([[1, 10, 7], [2, 20, 7], [3, 30, null]] as [$entryId, $feedId, $canonicalItemId]) {
            $run->add(
                new WindowEntryModel(
                    $entryId,
                    $feedId,
                    null,
                    'Hafenstreik',
                    false,
                    null,
                    self::later(),
                    new EntryLinksModel(null, null, null),
                    $canonicalItemId,
                ),
                [],
                null,
            );
        }

        self::assertSame([[1, 2]], $run->entriesSharingAUrlOrItem());
    }

    private function weightSharedWith(WindowEntryModel $later): float
    {
        $run = TrendingRuns::of(TrendingWindow::Day);
        $run->add(TrendingRuns::entry(1, 10, 'Storm Hamburg harbour', '2031-05-14 09:00:00'), [], null);
        $run->add($later, [], null);

        return $run->headlines->sharedWeightsWithEarlier($later->id)[1];
    }

    private static function later(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2031-05-14 10:00:00', new \DateTimeZone('UTC'));
    }
}
