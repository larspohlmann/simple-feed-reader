<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\CleanupMarker;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\PhraseMarkers;
use PHPUnit\Framework\TestCase;

final class PhraseMarkersTest extends TestCase
{
    private PhraseMarkers $markers;

    protected function setUp(): void
    {
        $this->markers = new PhraseMarkers();
    }

    public function testReportsAShareRowTheWidgetRemoverLeftBehind(): void
    {
        self::assertSame(['chrome_share'], $this->codesFor('<p>Diesen Artikel teilen</p>'));
    }

    public function testReportsEachFamilyOnceHoweverManyLinesMatchIt(): void
    {
        // A share bar renders one line per network; eight findings for one bar
        // would bury every other marker on the page.
        $html = '<p>Auf Facebook teilen</p><p>Auf X teilen</p><p>Per WhatsApp</p>';

        self::assertSame(['chrome_share'], $this->codesFor($html));
    }

    public function testAWordInsideRealProseIsNotFurniture(): void
    {
        $article = 'Der Verlag stellte seinen Newsletter ein, nachdem die Redaktion ueber Monate '
            . 'hinweg vergeblich versucht hatte, genug Abonnenten zu gewinnen, um die Kosten der '
            . 'woechentlichen Ausgabe zu decken, und die Anzeigenerloese weiter zurueckgingen.';

        self::assertSame([], $this->codesFor('<p>' . $article . '</p>'));
    }

    public function testNamesTheOffendingLineSoTheFindingCanBeJudgedWithoutOpeningThePage(): void
    {
        $markers = $this->markers->detect(ExtractedBody::fromHtml('<p>Mehr zum Thema</p>'));

        self::assertStringContainsString('Mehr zum Thema', $markers[0]->detail);
        self::assertSame('EdgeBoilerplateTrimmer', $markers[0]->suspect);
    }

    /** @return list<string> */
    private function codesFor(string $html): array
    {
        return array_map(
            static fn (CleanupMarker $marker): string => $marker->code,
            $this->markers->detect(ExtractedBody::fromHtml($html)),
        );
    }
}
