<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionResult;
use App\Service\ReaderAudit\AuditFinding;
use App\Service\ReaderAudit\BodyShapeMarkers;
use App\Service\ReaderAudit\CleanupMarkers;
use App\Service\ReaderAudit\LeadingChromeMarkers;
use App\Service\ReaderAudit\PhraseMarkers;
use App\Service\ReaderAudit\ReaderAuditRunner;
use App\Service\ReaderAudit\ReaderLink;
use App\Service\ReaderAudit\SampledEntry;
use App\Service\ReaderAudit\SocialWidgetMarkers;
use App\Tests\Support\FakeArticleExtractor;
use PHPUnit\Framework\TestCase;

final class ReaderAuditRunnerTest extends TestCase
{
    private const string CHROME_BODY = '<ul><li><a href="/a">Politik</a></li><li><a href="/b">Wirtschaft</a></li>'
        . '<li><a href="/c">Kultur</a></li><li><a href="/d">Sport</a></li></ul><p>Kurz.</p>';

    public function testReportsWhatTheCleanersLeftBehindOnAnExtractedArticle(): void
    {
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(
            ExtractionResult::ok('https://example.test/a', 'Titel', null, null, self::CHROME_BODY, null),
        );

        $finding = $this->auditOne($extractor);

        self::assertTrue($finding->extracted);
        self::assertContains('leading_link_list', $finding->markerCodes());
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

    public function testCarriesTheSampledEntrysIdentityIntoTheFinding(): void
    {
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok('https://example.test/a', 'Titel', null, null, '<p>x</p>', null));

        $finding = $this->auditOne($extractor);

        self::assertSame(7, $finding->entryId);
        self::assertSame(11, $finding->feedId);
        self::assertSame('Ein Feed', $finding->feedTitle);
        self::assertSame('Eine Schlagzeile', $finding->title);
        self::assertSame('https://example.test/a', $finding->sourceUrl);
    }

    public function testMeasuresTheCleanedBodyForTheReport(): void
    {
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok(
            'https://example.test/a',
            'Titel',
            null,
            null,
            '<p>Text mit <a href="/a">Link</a></p><img src="https://example.test/a.jpg">',
            null,
        ));

        $finding = $this->auditOne($extractor);

        self::assertSame(13, $finding->metrics['chars']);
        self::assertSame(1, $finding->metrics['paragraphs']);
        self::assertSame(1, $finding->metrics['links']);
        self::assertSame(1, $finding->metrics['images']);
        self::assertSame(1, $finding->metrics['leadingBlocks']);
    }

    public function testAFailedExtractionReportsZeroesRatherThanNoMeasurements(): void
    {
        // The report prints the metric line for every candidate; a missing key
        // there would be an undefined index in the renderer, not a blank.
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::failed('https://example.test/a', 'mismatch'));

        $finding = $this->auditOne($extractor);

        self::assertSame(
            ['chars' => 0, 'paragraphs' => 0, 'links' => 0, 'images' => 0, 'leadingBlocks' => 0],
            $finding->metrics,
        );
    }

    public function testTheCoverageGateRunsOverTheExtractionExactlyAsTheEndpointDoes(): void
    {
        // Without the gate the audit would call a confident-but-wrong extraction
        // clean, which is the one failure the reader already knows how to catch.
        $feedArticle = str_repeat('Ein Satz, den die Extraktion ebenfalls enthalten muesste. ', 30);
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok(
            'https://example.test/a',
            'Titel',
            null,
            null,
            '<p>Etwas voellig anderes stand auf der Seite und wurde stattdessen genommen.</p>',
            null,
        ));

        $runner = new ReaderAuditRunner($extractor, new ExtractionCoverageGate(), $this->markers());
        $entry = new SampledEntry(
            7,
            42,
            11,
            'Ein Feed',
            'Eine Schlagzeile',
            'https://example.test/a',
            $feedArticle,
            false,
        );
        $findings = iterator_to_array($runner->run([$entry], new ReaderLink('http://localhost:4200')));

        self::assertSame(['extraction_failed_mismatch'], $findings[0]->markerCodes());
    }

    public function testEveryEntryHandedInComesBackAsAFinding(): void
    {
        $extractor = new FakeArticleExtractor();
        $extractor->willReturn(ExtractionResult::ok('https://example.test/a', 'Titel', null, null, '<p>x</p>', null));
        $runner = new ReaderAuditRunner($extractor, new ExtractionCoverageGate(), $this->markers());

        $entries = [
            new SampledEntry(1, 42, 11, 'A', 'Eins', 'https://example.test/1', null, false),
            new SampledEntry(2, 42, 11, 'A', 'Zwei', 'https://example.test/2', null, false),
        ];
        $findings = iterator_to_array($runner->run($entries, new ReaderLink('http://localhost:4200')));

        self::assertSame([1, 2], array_map(static fn (AuditFinding $f): int => $f->entryId, $findings));
    }

    public function testACrashedPageStillCarriesItsLinkSoItCanBeOpened(): void
    {
        $throwing = new class implements ArticleExtractorInterface {
            public function extract(string $url, ?string $entryTitle = null): ExtractionResult
            {
                throw new \RuntimeException('lexbor gave up');
            }
        };

        $finding = $this->auditOne($throwing);

        self::assertSame('http://localhost:4200/?subscription=42&entry=7-eine-schlagzeile', $finding->readerLink);
        self::assertSame(4, $finding->markers[0]->weight);
        self::assertSame('the pipeline threw', $finding->markers[0]->suspect);
        self::assertSame(['chars' => 0], $finding->metrics);
    }

    private function markers(): CleanupMarkers
    {
        return new CleanupMarkers(
            new LeadingChromeMarkers(),
            new SocialWidgetMarkers(),
            new BodyShapeMarkers(),
            new PhraseMarkers(),
        );
    }

    private function auditOne(ArticleExtractorInterface $extractor): AuditFinding
    {
        $runner = new ReaderAuditRunner(
            $extractor,
            new ExtractionCoverageGate(),
            new CleanupMarkers(
                new LeadingChromeMarkers(),
                new SocialWidgetMarkers(),
                new BodyShapeMarkers(),
                new PhraseMarkers(),
            ),
        );

        $entry = new SampledEntry(7, 42, 11, 'Ein Feed', 'Eine Schlagzeile', 'https://example.test/a', null, false);
        $findings = iterator_to_array($runner->run([$entry], new ReaderLink('http://localhost:4200')));

        return $findings[0];
    }
}
