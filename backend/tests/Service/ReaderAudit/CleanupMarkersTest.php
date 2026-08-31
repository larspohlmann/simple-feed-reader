<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\Reader\ExtractionResult;
use App\Service\ReaderAudit\BodyShapeMarkers;
use App\Service\ReaderAudit\CleanupMarkers;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\LeadingChromeMarkers;
use App\Service\ReaderAudit\PhraseMarkers;
use App\Service\ReaderAudit\SampledEntry;
use App\Service\ReaderAudit\SocialWidgetMarkers;
use PHPUnit\Framework\TestCase;

final class CleanupMarkersTest extends TestCase
{
    private CleanupMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = new CleanupMarkers(
            new LeadingChromeMarkers(),
            new SocialWidgetMarkers(),
            new BodyShapeMarkers(),
            new PhraseMarkers(),
        );
    }

    public function testAFailedExtractionEarnsOnlyTheReasonItFailed(): void
    {
        $failed = ExtractionResult::failed('https://example.test/a', 'mismatch');
        $markers = $this->markers->detect($failed, $this->entry(), null);

        self::assertCount(1, $markers);
        self::assertSame('extraction_failed_mismatch', $markers[0]->code);
        self::assertStringContainsString('CoverageGate', $markers[0]->suspect);
    }

    public function testTheCoverageGateOutranksAFetchFailureBecauseItMeansTheCleanersMisread(): void
    {
        $mismatch = $this->markers->detect(ExtractionResult::failed(null, 'mismatch'), $this->entry(), null);
        $unreachable = $this->markers->detect(ExtractionResult::failed(null, 'fetch'), $this->entry(), null);

        self::assertGreaterThan($unreachable[0]->weight, $mismatch[0]->weight);
    }

    public function testASuccessfulExtractionIsMeasuredByShapeAndByWording(): void
    {
        $html = '<ul>' . str_repeat('<li><a href="/x">Ressort</a></li>', 4) . '</ul>'
            . '<p><a href="https://x.com/intent/tweet">Teilen</a></p>';
        $result = ExtractionResult::ok('https://example.test/a', 'Titel', null, null, $html, null);

        $codes = array_map(
            static fn ($marker): string => $marker->code,
            $this->markers->detect($result, $this->entry(), ExtractedBody::fromHtml($html)),
        );

        self::assertContains('leading_link_list', $codes);
        self::assertContains('share_intent_link', $codes);
    }

    private function entry(): SampledEntry
    {
        return new SampledEntry(7, 3, 11, 'Ein Feed', 'Eine Schlagzeile', 'https://example.test/a', null, false);
    }
}
