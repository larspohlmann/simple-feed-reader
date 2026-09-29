<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\ReaderAudit\CleanupMarkers;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Service\ReaderAudit\Model\SampledEntryModel;
use App\Tests\Support\AuditMarkers;
use PHPUnit\Framework\TestCase;

final class CleanupMarkersTest extends TestCase
{
    private CleanupMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = AuditMarkers::cleanupMarkers();
    }

    public function testNoFailedExtractionIsAFindingWhateverItsReason(): void
    {
        // Whatever went wrong, the reader falls back to the feed body and shows
        // the user the original. That is a real outcome and no cleaner changes
        // it; listing it filled the report with work nobody could do (#744).
        foreach (ExtractionFailure::cases() as $reason) {
            $failed = ExtractionResultModel::failed(null, $reason);

            self::assertSame([], $this->markers->detect($failed, $this->entry(), null), $reason->value);
        }
    }

    public function testABodyThatCouldNotBeMeasuredEarnsNothingEither(): void
    {
        $ok = ExtractionResultModel::ok('https://example.test/a', 'Titel', null, null, '<p>x</p>', null);

        self::assertSame([], $this->markers->detect($ok, $this->entry(), null));
    }

    public function testASuccessfulExtractionIsMeasuredByShapeAndByWording(): void
    {
        $html = '<ul>' . str_repeat('<li><a href="/x">Ressort</a></li>', 4) . '</ul>'
            . '<p><a href="https://x.com/intent/tweet?url=https://example.test/a">Teilen</a></p>';
        $result = ExtractionResultModel::ok('https://example.test/a', 'Titel', null, null, $html, null);

        $codes = array_map(
            static fn ($marker): string => $marker->code,
            $this->markers->detect($result, $this->entry(), ExtractedBodyModel::fromHtml($html)),
        );

        self::assertContains('leading_link_list', $codes);
        self::assertContains('share_intent_link', $codes);
    }

    public function testIncludesTheLeadingEngagementMarker(): void
    {
        $html = '<p>1.251 Klicks</p><p>' . str_repeat('Artikeltext. ', 20) . '</p>';
        $result = ExtractionResultModel::ok('https://example.test/a', 'Titel', null, null, $html, null);

        $codes = array_map(
            static fn ($marker): string => $marker->code,
            $this->markers->detect($result, $this->entry(), ExtractedBodyModel::fromHtml($html)),
        );

        self::assertContains('leading_engagement_chrome', $codes);
    }

    private function entry(): SampledEntryModel
    {
        return new SampledEntryModel(7, 3, 11, 'Ein Feed', 'Eine Schlagzeile', 'https://example.test/a', null, false);
    }
}
