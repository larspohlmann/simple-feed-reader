<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\RecommendationFeedJson;
use App\Repository\EntryListRow;
use App\Repository\RecommendationFeedRow;
use PHPUnit\Framework\TestCase;

final class RecommendationFeedJsonTest extends TestCase
{
    public function testPageOmitsBothDebugAnnotations(): void
    {
        $result = RecommendationFeedJson::page([$this->row()], null);

        // Debug off keeps neither the score nor the reason (#342): the reason
        // used to leak through with the score hidden, which read as inconsistent.
        self::assertArrayNotHasKey('recommendationScore', $result['entries'][0]);
        self::assertArrayNotHasKey('recommendationReason', $result['entries'][0]);
    }

    public function testPageWithScoresIncludesBothDebugAnnotations(): void
    {
        $result = RecommendationFeedJson::pageWithScores([$this->row()], null);

        self::assertSame(77, $result['entries'][0]['recommendationScore']);
        self::assertSame('Matches your interest in g1', $result['entries'][0]['recommendationReason']);
    }

    public function testPageAlwaysCarriesRunIdAndGeneratedAt(): void
    {
        $result = RecommendationFeedJson::page([$this->row()], null);

        // Present even with debug OFF — the divider is a normal-user feature.
        self::assertSame(1, $result['entries'][0]['runId']);
        self::assertSame('2026-08-07T09:05:00+00:00', $result['entries'][0]['runGeneratedAt']);
    }

    public function testPageWithScoresAlsoCarriesRunIdAndGeneratedAt(): void
    {
        $result = RecommendationFeedJson::pageWithScores([$this->row()], null);

        self::assertSame(1, $result['entries'][0]['runId']);
        self::assertSame('2026-08-07T09:05:00+00:00', $result['entries'][0]['runGeneratedAt']);
    }

    public function testRunGeneratedAtIsNullWhenTheRowCarriesNoGenerationTime(): void
    {
        $result = RecommendationFeedJson::page([$this->rowWithoutGenerationTime()], null);

        // Defensive: the field is nullable, so a row lacking a completion time
        // serialises the key as null rather than dereferencing null.
        self::assertArrayHasKey('runGeneratedAt', $result['entries'][0]);
        self::assertNull($result['entries'][0]['runGeneratedAt']);
    }

    public function testPageWithScoresCarriesANullScoreForRowsWrittenBeforeTheColumnExisted(): void
    {
        $result = RecommendationFeedJson::pageWithScores([$this->row(null)], null);

        self::assertArrayHasKey('recommendationScore', $result['entries'][0]);
        self::assertNull($result['entries'][0]['recommendationScore']);
    }

    private function row(?int $score = 77): RecommendationFeedRow
    {
        return $this->rowGeneratedAt(new \DateTimeImmutable('2026-08-07T09:05:00Z'), $score);
    }

    private function rowWithoutGenerationTime(): RecommendationFeedRow
    {
        return $this->rowGeneratedAt(null, 77);
    }

    private function rowGeneratedAt(?\DateTimeImmutable $runGeneratedAt, ?int $score): RecommendationFeedRow
    {
        $feed = new Feed('https://example.com/feed.xml');
        $entry = new Entry(
            $feed,
            'g1',
            'https://example.com/1',
            'Post 1',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );

        $listRow = new EntryListRow(
            entry: $entry,
            subscriptionId: 1,
            subscriptionTitle: 'Seeded',
            isRead: false,
            isFavorite: false,
            isKept: false,
            isViewed: false,
            markedReadUntil: null,
        );

        return new RecommendationFeedRow(
            row: $listRow,
            reason: 'Matches your interest in g1',
            runId: 1,
            position: 1,
            score: $score,
            runGeneratedAt: $runGeneratedAt,
        );
    }
}
