<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Http\FeedAnnotationVisibility;
use App\Http\RecommendationFeedJson;
use App\Repository\EntryListRow;
use App\Repository\RecommendationFeedRow;
use PHPUnit\Framework\TestCase;

final class RecommendationFeedJsonTest extends TestCase
{
    public function testShownIncludesTheReasonAndItsScoreTogether(): void
    {
        $result = RecommendationFeedJson::page(
            [$this->row()],
            null,
            new FeedAnnotationVisibility(showExplanation: true),
        );

        self::assertSame('Matches your interest in g1', $result['entries'][0]['recommendationReason']);
        self::assertSame(77, $result['entries'][0]['recommendationScore']);
        // The annotations augment the entry, they do not replace it: the base
        // keys (here runId) must survive alongside what is appended.
        self::assertSame(1, $result['entries'][0]['runId']);
    }

    public function testHiddenOmitsTheReasonAndItsScoreTogether(): void
    {
        $result = RecommendationFeedJson::page(
            [$this->row()],
            null,
            new FeedAnnotationVisibility(showExplanation: false),
        );

        self::assertArrayNotHasKey('recommendationReason', $result['entries'][0]);
        self::assertArrayNotHasKey('recommendationScore', $result['entries'][0]);
    }

    public function testPageAlwaysCarriesRunIdAndGeneratedAtRegardlessOfVisibility(): void
    {
        $result = RecommendationFeedJson::page(
            [$this->row()],
            null,
            new FeedAnnotationVisibility(showExplanation: false),
        );

        // Present even with both annotations hidden — the divider is a
        // normal-user feature (#348).
        self::assertSame(1, $result['entries'][0]['runId']);
        self::assertSame('2026-08-07T09:05:00+00:00', $result['entries'][0]['runGeneratedAt']);
    }

    public function testRunGeneratedAtIsNullWhenTheRowCarriesNoGenerationTime(): void
    {
        $result = RecommendationFeedJson::page(
            [$this->rowWithoutGenerationTime()],
            null,
            new FeedAnnotationVisibility(showExplanation: false),
        );

        // Defensive: the field is nullable, so a row lacking a completion time
        // serialises the key as null rather than dereferencing null.
        self::assertArrayHasKey('runGeneratedAt', $result['entries'][0]);
        self::assertNull($result['entries'][0]['runGeneratedAt']);
    }

    public function testScoreKeyIsNullForRowsWrittenBeforeTheColumnExisted(): void
    {
        $result = RecommendationFeedJson::page(
            [$this->row(null)],
            null,
            new FeedAnnotationVisibility(showExplanation: true),
        );

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
            viewedAt: null,
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
