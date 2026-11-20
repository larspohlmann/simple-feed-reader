<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Run\Support;

use App\Service\Content\LinkCard\Model\StoryLinkModel;
use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Curation\Recommendation\Run\Support\OneWinnerPerArticle;
use PHPUnit\Framework\TestCase;

final class OneWinnerPerArticleTest extends TestCase
{
    public function testTwoPostsSharingOneArticleKeepTheBestRanked(): void
    {
        $winners = [$this->winner(1, 90), $this->winner(2, 80), $this->winner(3, 70)];
        $lines = [1 => $this->post(1, 'shared'), 2 => $this->post(2, 'shared'), 3 => $this->post(3, 'other')];

        self::assertSame([1, 3], $this->idsOf(OneWinnerPerArticle::filterWinners($winners, $lines)));
    }

    public function testTheOutletArticleBeatsBetterRankedPosts(): void
    {
        $winners = [$this->winner(900, 95), $this->winner(700, 90), $this->winner(600, 80), $this->winner(500, 70)];
        $lines = [
            900 => $this->post(900, 'shared'),
            700 => $this->post(700, 'unrelated'),
            600 => $this->outletArticle(600, 'shared'),
            500 => $this->post(500, 'shared'),
        ];

        self::assertSame([700, 600], $this->idsOf(OneWinnerPerArticle::filterWinners($winners, $lines)));
    }

    public function testTheBestRankedOutletWinnerOfAKeyStaysAndTheRestDrop(): void
    {
        $winners = [$this->winner(1, 90), $this->winner(2, 80), $this->winner(3, 70)];
        $lines = [
            1 => $this->post(1, 'shared'),
            2 => $this->outletArticle(2, 'shared'),
            3 => $this->outletArticle(3, 'shared'),
        ];

        self::assertSame([2], $this->idsOf(OneWinnerPerArticle::filterWinners($winners, $lines)));
    }

    public function testWinnersWithoutAnArticleKeyAllStay(): void
    {
        $winners = [$this->winner(1, 90), $this->winner(2, 80)];
        $lines = [1 => $this->plainLine(1), 2 => $this->plainLine(2)];

        self::assertSame([1, 2], $this->idsOf(OneWinnerPerArticle::filterWinners($winners, $lines)));
    }

    public function testAWinnerWithoutALineStaysUngrouped(): void
    {
        $winners = [$this->winner(1, 90), $this->winner(2, 80), $this->winner(3, 70)];
        $lines = [2 => $this->post(2, 'shared'), 3 => $this->post(3, 'shared')];

        self::assertSame([1, 2], $this->idsOf(OneWinnerPerArticle::filterWinners($winners, $lines)));
    }

    public function testDifferentArticlesAllStay(): void
    {
        $winners = [$this->winner(1, 90), $this->winner(2, 80)];
        $lines = [1 => $this->post(1, 'one'), 2 => $this->outletArticle(2, 'two')];

        self::assertSame($winners, OneWinnerPerArticle::filterWinners($winners, $lines));
    }

    /**
     * @param list<array{id: int, score: int, reason: string}> $winners
     *
     * @return list<int>
     */
    private function idsOf(array $winners): array
    {
        return array_column($winners, 'id');
    }

    /** @return array{id: int, score: int, reason: string} */
    private function winner(int $entryId, int $score): array
    {
        return ['id' => $entryId, 'score' => $score, 'reason' => 'reason ' . $entryId];
    }

    private function post(int $entryId, string $urlHash): ArticleLineModel
    {
        return new ArticleLineModel(
            $entryId,
            'Post',
            'Feed',
            '2026-10-01',
            null,
            new StoryLinkModel($urlHash, 'Story', null, 'Example'),
        );
    }

    private function outletArticle(int $entryId, string $urlHash): ArticleLineModel
    {
        return new ArticleLineModel($entryId, 'Article', 'Feed', '2026-10-01', null, null, $urlHash);
    }

    private function plainLine(int $entryId): ArticleLineModel
    {
        return new ArticleLineModel($entryId, 'Post', 'Feed', '2026-10-01', null);
    }
}
