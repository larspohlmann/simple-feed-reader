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

    public function testNoFailedExtractionIsAFindingWhateverItsReason(): void
    {
        // Whatever went wrong, the reader falls back to the feed body and shows
        // the user the original. That is a real outcome and no cleaner changes
        // it; listing it filled the report with work nobody could do (#744).
        foreach (['fetch', 'no_url', 'unextractable', 'empty', 'mismatch'] as $reason) {
            $failed = ExtractionResult::failed(null, $reason);

            self::assertSame([], $this->markers->detect($failed, $this->entry(), null), $reason);
        }
    }

    public function testABodyThatCouldNotBeMeasuredEarnsNothingEither(): void
    {
        $ok = ExtractionResult::ok('https://example.test/a', 'Titel', null, null, '<p>x</p>', null);

        self::assertSame([], $this->markers->detect($ok, $this->entry(), null));
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
