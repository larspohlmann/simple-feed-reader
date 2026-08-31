<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\AuditFinding;
use App\Service\ReaderAudit\AuditFindings;
use App\Service\ReaderAudit\CleanupMarker;
use PHPUnit\Framework\TestCase;

final class AuditFindingsTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }
    }

    public function testReadsEveryShardFileIntoOneCollection(): void
    {
        $findings = AuditFindings::fromJsonlFiles([
            $this->jsonl([$this->finding(1, 11, 'A', [$this->marker('body_short', 2)])]),
            $this->jsonl([$this->finding(2, 12, 'B', [])]),
        ]);

        self::assertSame(2, $findings->audited());
        self::assertSame(2, $findings->feedCount());
    }

    public function testRanksTheFlaggedArticlesWorstFirstAndDropsTheCleanOnes(): void
    {
        $findings = AuditFindings::fromJsonlFiles([$this->jsonl([
            $this->finding(1, 11, 'A', [$this->marker('body_short', 2)]),
            $this->finding(2, 11, 'A', []),
            $this->finding(3, 11, 'A', [$this->marker('link_dense', 3), $this->marker('chrome_share', 2)]),
        ])]);

        self::assertSame([3, 1], array_map(static fn (AuditFinding $f): int => $f->entryId, $findings->ranked()));
    }

    public function testRanksFeedsByTheShareThatFailsSoAProlificFeedCannotTopTheListOnVolumeAlone(): void
    {
        $findings = AuditFindings::fromJsonlFiles([$this->jsonl([
            $this->finding(1, 11, 'Loud', [$this->marker('body_short', 2)]),
            $this->finding(2, 11, 'Loud', []),
            $this->finding(3, 11, 'Loud', []),
            $this->finding(4, 12, 'Broken', [$this->marker('body_short', 2)]),
        ])]);

        $feeds = $findings->byFeed();

        self::assertSame('Broken', $feeds[0]['feed']);
        self::assertSame(1.0, $feeds[0]['share']);
    }

    public function testTalliesHowManyArticlesEachSuspectAccountsFor(): void
    {
        $findings = AuditFindings::fromJsonlFiles([$this->jsonl([
            $this->finding(1, 11, 'A', [$this->marker('link_dense', 3, 'NavigationChromeTrimmer')]),
            $this->finding(2, 11, 'A', [$this->marker('link_list', 3, 'NavigationChromeTrimmer')]),
            $this->finding(3, 11, 'A', [$this->marker('chrome_share', 2, 'ShareWidgetRemover')]),
        ])]);

        self::assertSame(
            ['NavigationChromeTrimmer' => 2, 'ShareWidgetRemover' => 1],
            $findings->tally(static fn (CleanupMarker $m): string => $m->suspect),
        );
    }

    public function testAFindingsScoreIsTheSumOfItsMarkerWeights(): void
    {
        $finding = $this->finding(1, 11, 'A', [$this->marker('a', 2), $this->marker('b', 3)]);

        self::assertSame(5, $finding->score());
    }

    public function testAnUnflaggedFindingScoresZero(): void
    {
        self::assertSame(0, $this->finding(1, 11, 'A', [])->score());
    }

    public function testAFindingRoundTripsThroughJsonWithEveryFieldIntact(): void
    {
        $original = $this->finding(7, 11, 'Ein Feed', [$this->marker('body_short', 2, 'EdgeBoilerplateTrimmer')]);

        $restored = AuditFindings::fromJsonlFiles([$this->jsonl([$original])])->findings[0];

        self::assertSame(7, $restored->entryId);
        self::assertSame(11, $restored->feedId);
        self::assertSame('Ein Feed', $restored->feedTitle);
        self::assertSame('Titel', $restored->title);
        self::assertSame('https://example.test/a', $restored->sourceUrl);
        self::assertSame('http://localhost:4200/?entry=1', $restored->readerLink);
        self::assertTrue($restored->extracted);
        self::assertSame(['chars' => 10], $restored->metrics);
        self::assertSame(['body_short'], $restored->markerCodes());
        self::assertSame('EdgeBoilerplateTrimmer', $restored->markers[0]->suspect);
        self::assertSame('detail', $restored->markers[0]->detail);
        self::assertSame(2, $restored->markers[0]->weight);
    }

    public function testCountsHowManyOfTheAuditedArticlesWereExtractedAtAll(): void
    {
        $failed = new AuditFinding(2, 11, 'A', 'T', 'u', 'l', false, [], []);

        $findings = AuditFindings::fromJsonlFiles([
            $this->jsonl([$this->finding(1, 11, 'A', []), $failed]),
        ]);

        self::assertSame(2, $findings->audited());
        self::assertSame(1, $findings->extracted());
    }

    public function testOneFeedAuditedTwiceCountsAsOneFeed(): void
    {
        $findings = AuditFindings::fromJsonlFiles([$this->jsonl([
            $this->finding(1, 11, 'A', []),
            $this->finding(2, 11, 'A', []),
        ])]);

        self::assertSame(1, $findings->feedCount());
    }

    public function testAFeedRowCarriesItsWorstScoreNotItsLast(): void
    {
        $findings = AuditFindings::fromJsonlFiles([$this->jsonl([
            $this->finding(1, 11, 'A', [$this->marker('a', 7)]),
            $this->finding(2, 11, 'A', [$this->marker('b', 2)]),
        ])]);

        self::assertSame(7, $findings->byFeed()[0]['worst']);
        self::assertSame(2, $findings->byFeed()[0]['audited']);
        self::assertSame(2, $findings->byFeed()[0]['flagged']);
    }

    public function testFeedsWithTheSameShareAreOrderedByHowManyArticlesFailed(): void
    {
        // Both feeds fail every article; the one that failed more of them is the
        // bigger problem and has to come first.
        $findings = AuditFindings::fromJsonlFiles([$this->jsonl([
            $this->finding(1, 11, 'Klein', [$this->marker('a', 2)]),
            $this->finding(2, 12, 'Gross', [$this->marker('a', 2)]),
            $this->finding(3, 12, 'Gross', [$this->marker('a', 2)]),
        ])]);

        self::assertSame('Gross', $findings->byFeed()[0]['feed']);
    }

    public function testAnEmptySweepAnswersEveryQuestionWithNothing(): void
    {
        $findings = AuditFindings::fromJsonlFiles([$this->jsonl([])]);

        self::assertSame(0, $findings->audited());
        self::assertSame(0, $findings->feedCount());
        self::assertSame([], $findings->ranked());
        self::assertSame([], $findings->byFeed());
    }

    /** @param list<AuditFinding> $findings */
    private function jsonl(array $findings): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'audit');
        $this->files[] = $path;
        $encode = static fn (AuditFinding $f): string => json_encode($f->toArray(), \JSON_THROW_ON_ERROR);
        $lines = array_map($encode, $findings);
        file_put_contents($path, $lines === [] ? '' : implode("\n", $lines) . "\n");

        return $path;
    }

    /** @param list<CleanupMarker> $markers */
    private function finding(int $entryId, int $feedId, string $feedTitle, array $markers): AuditFinding
    {
        return new AuditFinding(
            $entryId,
            $feedId,
            $feedTitle,
            'Titel',
            'https://example.test/a',
            'http://localhost:4200/?entry=1',
            true,
            $markers,
            ['chars' => 10],
        );
    }

    private function marker(string $code, int $weight, string $suspect = 'irgendwo'): CleanupMarker
    {
        return new CleanupMarker($code, $weight, $suspect, 'detail');
    }
}
