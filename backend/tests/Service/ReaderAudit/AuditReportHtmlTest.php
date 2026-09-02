<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\AuditFinding;
use App\Service\ReaderAudit\AuditFindings;
use App\Service\ReaderAudit\AuditReportHtml;
use App\Service\ReaderAudit\CleanupMarker;
use PHPUnit\Framework\TestCase;

final class AuditReportHtmlTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '') {
            unlink($this->file);
        }
    }

    public function testEveryCandidateCarriesTheLinkThatOpensItInTheReader(): void
    {
        $html = $this->render([$this->finding('Ein Titel', 'http://localhost:4200/?subscription=1&entry=7-ein-titel')]);

        self::assertStringContainsString('href="http://localhost:4200/?subscription=1&amp;entry=7-ein-titel"', $html);
        self::assertStringContainsString('Ein Titel', $html);
    }

    public function testTheSourcePageIsPrintedAsItsFullUrlNotAsAWordToClickOn(): void
    {
        $html = $this->render([$this->finding('Ein Titel', 'http://localhost:4200/?entry=7')]);

        self::assertStringContainsString('>https://example.test/a</a>', $html);
        self::assertStringNotContainsString('source page', $html);
    }

    public function testTheSummaryCountsWhatWasAuditedExtractedAndFlagged(): void
    {
        $html = $this->render([
            $this->finding('Erster', 'http://localhost:4200/?entry=1'),
            $this->clean('Zweiter'),
            $this->clean('Dritter'),
            $this->failed('Vierter'),
        ]);

        self::assertStringContainsString('Reader cleanup audit', $html);
        self::assertStringContainsString('2026-08-31 10:00', $html);
        self::assertStringContainsString('4 articles over 1 feeds; 3 extracted, 2 flagged (50%)', $html);
    }

    public function testAnEmptySweepReportsNoShareRatherThanDividingByZero(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('0 articles over 0 feeds; 0 extracted, 0 flagged (0%)', $html);
    }

    public function testTheSuspectTableCountsTheArticlesEachStageAccountsFor(): void
    {
        $html = $this->render([
            $this->finding('Erster', 'http://localhost:4200/?entry=1'),
            $this->finding('Zweiter', 'http://localhost:4200/?entry=2'),
        ]);

        self::assertStringContainsString('Where to look first', $html);
        self::assertStringContainsString('<th>Stage</th>', $html);
        self::assertStringContainsString('<td>EdgeBoilerplateTrimmer</td><td class="n">2</td>', $html);
    }

    public function testTheFeedTableCarriesEveryColumnOfTheFailureRate(): void
    {
        $html = $this->render([
            $this->finding('Erster', 'http://localhost:4200/?entry=1'),
            $this->clean('Zweiter'),
        ]);

        self::assertStringContainsString('Feeds that failed', $html);
        self::assertStringContainsString('<th>Feed</th>', $html);
        self::assertStringContainsString('<th class="n">Audited</th>', $html);
        self::assertStringContainsString('<th class="n">Flagged</th>', $html);
        self::assertStringContainsString('<th class="n">Share</th>', $html);
        self::assertStringContainsString('<th class="n">Worst score</th>', $html);
        self::assertStringContainsString(
            '<td>Ein Feed</td><td class="n">2</td><td class="n">1</td><td class="n">50%</td><td class="n">2</td>',
            $html,
        );
    }

    public function testTheCandidateHeadingSaysHowManyOfTheFlaggedAreListed(): void
    {
        $findings = [];
        foreach (range(1, 4) as $index) {
            $findings[] = $this->finding('Nummer ' . $index, 'http://localhost:4200/?entry=' . $index);
        }

        $html = $this->render($findings, maxCandidates: 3);

        self::assertStringContainsString('Candidates (3 worst of 4 flagged)', $html);
        self::assertStringNotContainsString('Nummer 4', $html);
    }

    public function testACleanArticleIsNotListedAsACandidate(): void
    {
        $html = $this->render([$this->clean('Sauber')]);

        self::assertStringContainsString('Candidates (0 worst of 0 flagged)', $html);
        self::assertStringNotContainsString('Sauber', $html);
    }

    public function testACandidateShowsItsScoreFeedMetricsAndEveryMarker(): void
    {
        $html = $this->render([$this->finding('Ein Titel', 'http://localhost:4200/?entry=7')]);

        self::assertStringContainsString('<span class="score">2</span>', $html);
        self::assertStringContainsString('<p class="meta">Ein Feed — chars 384</p>', $html);
        self::assertStringContainsString('<code>body_short</code>', $html);
        self::assertStringContainsString('<span class="suspect">EdgeBoilerplateTrimmer</span>', $html);
        self::assertStringContainsString('<span class="detail">384 characters</span>', $html);
    }

    public function testTheMetricLineNamesEveryMeasurementItCarries(): void
    {
        $finding = new AuditFinding(7, 11, 'Ein Feed', 'T', 'https://example.test/a', 'http://l/?entry=7', true, [
            new CleanupMarker('body_short', 2, 'EdgeBoilerplateTrimmer', '384 characters'),
        ], ['chars' => 384, 'links' => 9]);

        self::assertStringContainsString('chars 384, links 9', $this->render([$finding]));
    }

    public function testTheWorstCandidateIsListedFirst(): void
    {
        $mild = $this->finding('Mild', 'http://localhost:4200/?entry=1');
        $severe = new AuditFinding(8, 11, 'Ein Feed', 'Schwer', 'https://example.test/b', 'http://l/?entry=8', true, [
            new CleanupMarker('leading_link_list', 4, 'NavigationChromeTrimmer', 'vier Punkte'),
        ], ['chars' => 10]);

        $html = $this->render([$mild, $severe]);

        self::assertLessThan(mb_strpos($html, 'Mild'), (int) mb_strpos($html, 'Schwer'));
    }

    public function testAHeadlineCarryingMarkupCannotBreakOutOfTheReport(): void
    {
        // Headlines are publisher-supplied; the report is opened in a browser.
        $html = $this->render([$this->finding('<script>alert(1)</script>', 'http://localhost:4200/?entry=7')]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @param list<AuditFinding> $findings */
    private function render(array $findings, int $maxCandidates = 300): string
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'audit');
        $encode = static fn (AuditFinding $f): string => json_encode($f->toArray(), \JSON_THROW_ON_ERROR);
        $lines = array_map($encode, $findings);
        file_put_contents($this->file, implode("\n", $lines) . "\n");

        $findings = AuditFindings::fromJsonlFiles([$this->file]);

        return (new AuditReportHtml($maxCandidates))->render($findings, '2026-08-31 10:00');
    }

    /** Every fixture is its own entry: a repeated id reads as one re-measured article (#783). */
    private int $nextEntryId = 1;

    private function clean(string $title): AuditFinding
    {
        $id = $this->nextEntryId++;

        return new AuditFinding($id, 11, 'Ein Feed', $title, 'https://example.test/c', 'http://l/?entry=9', true, [], [
            'chars' => 900,
        ]);
    }

    private function failed(string $title): AuditFinding
    {
        $id = $this->nextEntryId++;

        return new AuditFinding($id, 11, 'Ein Feed', $title, 'https://example.test/d', 'http://l/?entry=10', false, [
            new CleanupMarker('no_paragraphs', 4, 'EdgeBoilerplateTrimmer', 'not one <p>'),
        ], ['chars' => 0]);
    }

    private function finding(string $title, string $link): AuditFinding
    {
        return new AuditFinding($this->nextEntryId++, 11, 'Ein Feed', $title, 'https://example.test/a', $link, true, [
            new CleanupMarker('body_short', 2, 'EdgeBoilerplateTrimmer', '384 characters'),
        ], ['chars' => 384]);
    }
}
