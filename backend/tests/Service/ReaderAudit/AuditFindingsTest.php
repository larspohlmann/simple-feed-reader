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

    /** @param list<AuditFinding> $findings */
    private function jsonl(array $findings): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'audit');
        $this->files[] = $path;
        $encode = static fn (AuditFinding $f): string => json_encode($f->toArray(), \JSON_THROW_ON_ERROR);
        $lines = array_map($encode, $findings);
        file_put_contents($path, implode("\n", $lines) . "\n");

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
