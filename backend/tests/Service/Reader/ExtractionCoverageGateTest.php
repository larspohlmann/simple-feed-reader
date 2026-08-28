<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionResult;
use PHPUnit\Framework\TestCase;

final class ExtractionCoverageGateTest extends TestCase
{
    private ExtractionCoverageGate $gate;

    protected function setUp(): void
    {
        $this->gate = new ExtractionCoverageGate();
    }

    /** A full article the feed also carries: the words line up, so it is trusted. */
    public function testKeepsAnExtractionThatReflectsTheFeedArticle(): void
    {
        $feed = $this->paragraph();
        $result = $this->okWith('<article>' . $feed . '<p>Plus a clean pull quote.</p></article>');

        self::assertSame($result, $this->gate->verify($result, '<div>' . $feed . '</div>'));
    }

    /** The bug: a substantial feed article, but the extraction grabbed unrelated page furniture. */
    public function testRejectsAnExtractionThatDoesNotReflectTheFeedArticle(): void
    {
        $verified = $this->gate->verify(
            $this->okWith('<p>+++ dein shop gegen meerweh +++ neu im shop eingetroffen +++</p>'),
            '<div>' . $this->paragraph() . '</div>',
        );

        self::assertFalse($verified->ok);
        self::assertSame('mismatch', $verified->reason);
        self::assertSame('https://site.test/post', $verified->url);
    }

    /** A truncated teaser is exactly what the reader exists to improve on: never gate it. */
    public function testTrustsTheExtractionWhenTheFeedCarriesOnlyAShortTeaser(): void
    {
        $result = $this->okWith('<p>A clean full article the feed never carried.</p>');

        self::assertSame($result, $this->gate->verify($result, '<p>A short teaser. Read more.</p>'));
    }

    /** Coverage exactly at the minimum is trusted — the `>=` boundary, and the math behind it. */
    public function testTrustsAnExtractionWhoseCoverageIsExactlyTheMinimum(): void
    {
        // 203 distinct words → 200 four-word shingles. The first 53 words share
        // 50 of them, so coverage is exactly 50 / 200 = 0.25 (== MIN_COVERAGE).
        $feed = $this->distinctWords(203);
        $result = $this->okWith('<p>' . $this->firstWords($feed, 53) . '</p>');

        self::assertTrue($this->gate->verify($result, '<p>' . $feed . '</p>')->ok);
    }

    /** Some words overlap, but well under the minimum: still the wrong article. */
    public function testRejectsAnExtractionWithSomeButTooLittleOverlap(): void
    {
        $feed = $this->distinctWords(203);
        $result = $this->okWith('<p>' . $this->firstWords($feed, 13) . '</p>'); // 10 / 200 = 0.05

        self::assertSame('mismatch', $this->gate->verify($result, '<p>' . $feed . '</p>')->reason);
    }

    /** The bar is counted in characters, not bytes: 500 two-byte letters stay a teaser. */
    public function testMeasuresTheFeedBarInCharactersNotBytes(): void
    {
        $result = $this->okWith('<p>quite unrelated body text here</p>');

        // 500 'ä' = 1000 bytes but 500 characters, below the substantial bar.
        self::assertTrue($this->gate->verify($result, '<p>' . str_repeat('ä', 500) . '</p>')->ok);
    }

    /** A feed body of exactly the substantial length is judged, not waved through. */
    public function testJudgesAFeedBodyThatIsExactlyTheSubstantialLength(): void
    {
        $feed = mb_substr(str_repeat('wort eins zwei drei ', 60), 0, 999) . 'x';
        self::assertSame(1000, mb_strlen($feed));
        $result = $this->okWith('<p>completely different words nothing shared</p>');

        self::assertSame('mismatch', $this->gate->verify($result, '<p>' . $feed . '</p>')->reason);
    }

    /** An empty extracted body shares nothing with a full feed article. */
    public function testRejectsAnEmptyExtractedBodyAgainstAFullFeedArticle(): void
    {
        $verified = $this->gate->verify($this->okWith(''), '<div>' . $this->paragraph() . '</div>');

        self::assertSame('mismatch', $verified->reason);
    }

    public function testLeavesAnAlreadyFailedExtractionUntouched(): void
    {
        $failed = ExtractionResult::failed('https://site.test/post', 'fetch');

        self::assertSame($failed, $this->gate->verify($failed, '<div>' . $this->paragraph() . '</div>'));
    }

    public function testTrustsTheExtractionWhenTheEntryHasNoFeedBody(): void
    {
        $result = $this->okWith('<p>Body.</p>');

        self::assertSame($result, $this->gate->verify($result, null));
    }

    /** `token1 token2 … tokenN` — every four-word window is a distinct shingle. */
    private function distinctWords(int $count): string
    {
        return implode(' ', array_map(static fn (int $i): string => 'token' . $i, range(1, $count)));
    }

    private function firstWords(string $text, int $count): string
    {
        return implode(' ', array_slice(explode(' ', $text), 0, $count));
    }

    private function okWith(string $contentHtml): ExtractionResult
    {
        return ExtractionResult::ok(
            url: 'https://site.test/post',
            title: 'The Title',
            byline: null,
            siteName: null,
            contentHtml: $contentHtml,
            excerpt: null,
        );
    }

    /** A distinct-worded article body comfortably past the substantial-feed bar. */
    private function paragraph(): string
    {
        return '<p>Gegen zwanzig Uhr fünfzig empfing die Rettungsleitstelle einen kaum '
            . 'verständlichen Notruf von einer polnischen Segelyacht nördlich von Sassnitz. '
            . 'Sechs Menschen, darunter drei Kinder, standen bereits bis zu den Schienbeinen '
            . 'im Wasser der Ostsee, während viel Seewasser in das zwölf Meter lange Boot '
            . 'eindrang und es langsam zu sinken drohte. Der Seenotrettungskreuzer erreichte '
            . 'den Havaristen nach wenigen Minuten, brachte eine leistungsstarke Lenzpumpe an '
            . 'Bord und stabilisierte die Lage. Anschließend nahm das Tochterboot die Yacht in '
            . 'Schlepp und brachte sie sicher in den Stadthafen von Sassnitz, wo die Feuerwehren '
            . 'die weiteren Sicherungsarbeiten übernahmen. Alle sechs Menschen an Bord blieben '
            . 'unverletzt, die Ursache des Wassereinbruchs blieb zunächst ungeklärt.</p>'
            . '<p>Die Steilküste des Nationalparks Jasmund erschwerte die Verständigung über '
            . 'Seefunk erheblich, sodass die Besatzung ihre genaue Position erst nach mehreren '
            . 'Versuchen durchgeben konnte. Zum Zeitpunkt des Seenotfalls herrschten schwache '
            . 'Winde aus südwestlicher Richtung und eine vergleichsweise ruhige See, was den '
            . 'freiwilligen Helfern die schwierige Bergung unter Zeitdruck wesentlich erleichterte.</p>';
    }
}
