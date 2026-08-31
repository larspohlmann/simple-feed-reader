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

    public function testAHeadlineCarryingMarkupCannotBreakOutOfTheReport(): void
    {
        // Headlines are publisher-supplied; the report is opened in a browser.
        $html = $this->render([$this->finding('<script>alert(1)</script>', 'http://localhost:4200/?entry=7')]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    /** @param list<AuditFinding> $findings */
    private function render(array $findings): string
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'audit');
        $encode = static fn (AuditFinding $f): string => json_encode($f->toArray(), \JSON_THROW_ON_ERROR);
        $lines = array_map($encode, $findings);
        file_put_contents($this->file, implode("\n", $lines) . "\n");

        return (new AuditReportHtml())->render(AuditFindings::fromJsonlFiles([$this->file]), '2026-08-31 10:00');
    }

    private function finding(string $title, string $link): AuditFinding
    {
        return new AuditFinding(7, 11, 'Ein Feed', $title, 'https://example.test/a', $link, true, [
            new CleanupMarker('body_short', 2, 'EdgeBoilerplateTrimmer', '384 characters'),
        ], ['chars' => 384]);
    }
}
