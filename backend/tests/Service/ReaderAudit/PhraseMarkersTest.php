<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\CleanupMarker;
use App\Service\ReaderAudit\ExtractedBody;
use App\Service\ReaderAudit\PhraseFamily;
use App\Service\ReaderAudit\PhraseMarkers;
use App\Service\ReaderAudit\PhraseScope;
use App\Service\ReaderAudit\SuspiciousPhrases;
use PHPUnit\Framework\TestCase;

final class PhraseMarkersTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle fuer einen '
        . 'Prosa-Block sicher ueberschreitet und damit die Stelle markiert, an der der '
        . 'Artikel beginnt und die Kopfzone endet, mit genug Zeichen dafuer. ';

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

    public function testChromeWordingUnderTheArticleIsTheSitesTailAndIsNotReported(): void
    {
        $article = '<p>' . str_repeat(self::PROSE, 2) . '</p><p>Mehr zum Thema</p>';

        self::assertSame([], $this->codesFor($article));
    }

    public function testAConsentLineInsideARealArticleIsItsOwnFinePrintNotAWall(): void
    {
        // correctiv.org: the newsletter box's "Datenschutzerklärung" sentence
        // sits inside a 3000-character article the reader renders correctly. A
        // wall is the ABSENCE of the article, so a body that has one cannot be
        // behind a wall (#744).
        $article = '<p>' . str_repeat(self::PROSE, 2) . '</p>'
            . '<p>Mit der Anmeldung willigen Sie der Verarbeitung Ihrer Daten gemäß unserer '
            . 'Datenschutzerklärung ein.</p>';

        self::assertSame([], $this->codesFor($article));
    }

    public function testTheSameConsentLineOnABodyThatNeverStartsIsAWall(): void
    {
        $wall = '<p>Wir verwenden Cookies</p><p>Alle akzeptieren</p>';

        self::assertSame(['wall_consent'], $this->codesFor($wall));
    }

    public function testEachFamilyMatchesItsFirstListedPhrase(): void
    {
        // A phrase silently dropped from the table is a publisher stopping to be
        // reported, with nothing failing to say so.
        foreach (SuspiciousPhrases::families() as $family) {
            $block = '<p>' . $family->phrases[0] . '</p>';

            self::assertSame([$family->code], $this->codesFor($block), $family->code);
        }
    }

    public function testEveryListedPhraseIsMatchedByTheFamilyThatListsIt(): void
    {
        // Not which phrase comes back — one listing contains another
        // ("menü" inside "hauptmenü") and the family answers with the first it
        // finds. What matters is that no listed wording goes unrecognised.
        foreach (SuspiciousPhrases::families() as $family) {
            foreach ($family->phrases as $phrase) {
                self::assertNotNull($family->matchIn($phrase), $family->code . ' / ' . $phrase);
            }
        }
    }

    public function testEachFamilyStopsMatchingOneCharacterPastItsBlockLimit(): void
    {
        // The limit is what separates a menu entry from a sentence, so it is
        // stated per family here and not only in the table.
        foreach (SuspiciousPhrases::families() as $family) {
            $phrase = $family->phrases[0];
            $atLimit = str_pad($phrase, $family->maxBlockChars, 'x');
            $overLimit = str_pad($phrase, $family->maxBlockChars + 1, 'x');

            self::assertNotNull($family->matchIn($atLimit), $family->code);
            self::assertNull($family->matchIn($overLimit), $family->code);
        }
    }

    public function testTheTableIsExactlyTheFamiliesTheAuditScoresOn(): void
    {
        // Spelled out rather than derived from the table, so that a family, a
        // weight, a block limit or a phrase silently disappearing fails here
        // instead of quietly narrowing what the sweep reports.
        $table = [];
        foreach (SuspiciousPhrases::families() as $family) {
            $table[$family->code] = [$family->weight, $family->maxBlockChars, $family->scope, \count($family->phrases)];
        }

        self::assertSame([
            'wall_consent' => [4, 600, PhraseScope::OnlyWhenNoArticle, 11],
            'wall_javascript' => [4, 600, PhraseScope::OnlyWhenNoArticle, 8],
            'wall_bot' => [4, 600, PhraseScope::OnlyWhenNoArticle, 7],
            'chrome_navigation' => [3, 30, PhraseScope::AboveTheArticle, 15],
            'chrome_share' => [3, 60, PhraseScope::AboveTheArticle, 13],
            'chrome_newsletter' => [2, 90, PhraseScope::AboveTheArticle, 4],
            'chrome_related' => [2, 90, PhraseScope::AboveTheArticle, 10],
            'chrome_advert' => [2, 60, PhraseScope::AboveTheArticle, 5],
        ], $table);
    }

    public function testAPageCanEarnSeveralFamiliesAtOnce(): void
    {
        $html = '<p>Anzeige</p><p>Newsletter</p><p>Kurz</p>';

        self::assertSame(['chrome_newsletter', 'chrome_advert'], $this->codesFor($html));
    }

    public function testALongOffendingLineIsCutAtOneHundredAndTwentyCharacters(): void
    {
        $atLimit = 'wir verwenden cookies ' . str_repeat('a', 98);
        $overLimit = $atLimit . 'b';

        self::assertStringEndsWith($atLimit, $this->detailFor($atLimit));
        self::assertStringEndsWith(mb_substr($overLimit, 0, 120) . '…', $this->detailFor($overLimit));
    }

    public function testTheCutCountsCharactersNotBytes(): void
    {
        $umlauts = 'wir verwenden cookies ' . str_repeat('ä', 98);

        self::assertStringEndsWith($umlauts, $this->detailFor($umlauts));
    }

    private function detailFor(string $blockText): string
    {
        $markers = $this->markers->detect(ExtractedBody::fromHtml('<p>' . $blockText . '</p>'));

        return $markers[0]->detail;
    }

    public function testALongOffendingLineIsShortenedButStillNamesThePhrase(): void
    {
        $long = 'Mehr zum Thema ' . str_repeat('a', 200);
        $markers = $this->markers->detect(ExtractedBody::fromHtml('<p>' . $long . '</p><p>x</p>'));

        self::assertSame([], $markers);
    }

    public function testTheDetailQuotesThePhraseAndTheWholeShortLine(): void
    {
        $markers = $this->markers->detect(ExtractedBody::fromHtml('<p>Anzeige</p>'));

        self::assertSame('"anzeige" in: Anzeige', $markers[0]->detail);
        self::assertSame(2, $markers[0]->weight);
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
