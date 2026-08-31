<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionResult;
use App\Service\ReaderAudit\AuditFinding;
use App\Service\ReaderAudit\CleanupMarkers;
use App\Service\ReaderAudit\PhraseMarkers;
use App\Service\ReaderAudit\ReaderAuditRunner;
use App\Service\ReaderAudit\ReaderLink;
use App\Service\ReaderAudit\SampledEntry;
use App\Service\ReaderAudit\StructureMarkers;
use App\Tests\Support\FakeArticleExtractor;
use PHPUnit\Framework\TestCase;

final class ReaderAuditRunnerTest extends TestCase
{
    public function testReportsWhatTheCleanersLeftBehindOnAnExtractedArticle(): void
    {
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(
            ExtractionResult::ok('https://example.test/a', 'Titel', null, null, '<p>Zu kurz.</p>', null),
        );

        $finding = $this->auditOne($extractor);

        self::assertTrue($finding->extracted);
        self::assertContains('body_short', $finding->markerCodes());
        self::assertSame('http://localhost:4200/?subscription=42&entry=7-eine-schlagzeile', $finding->readerLink);
    }

    public function testAPageThatKillsThePipelineCostsOneFindingAndNotTheSweep(): void
    {
        // A thousand publishers produce markup no fixture holds; the sweep has to
        // survive the one page that throws.
        $throwing = new class implements ArticleExtractorInterface {
            public function extract(string $url, ?string $entryTitle = null): ExtractionResult
            {
                throw new \RuntimeException('lexbor gave up');
            }
        };

        $finding = $this->auditOne($throwing);

        self::assertFalse($finding->extracted);
        self::assertSame(['audit_error'], $finding->markerCodes());
        self::assertSame('lexbor gave up', $finding->markers[0]->detail);
    }

    private function auditOne(ArticleExtractorInterface $extractor): AuditFinding
    {
        $runner = new ReaderAuditRunner(
            $extractor,
            new ExtractionCoverageGate(),
            new CleanupMarkers(new StructureMarkers(), new PhraseMarkers()),
        );

        $entry = new SampledEntry(7, 42, 11, 'Ein Feed', 'Eine Schlagzeile', 'https://example.test/a', null, false);
        $findings = iterator_to_array($runner->run([$entry], new ReaderLink('http://localhost:4200')));

        return $findings[0];
    }
}
