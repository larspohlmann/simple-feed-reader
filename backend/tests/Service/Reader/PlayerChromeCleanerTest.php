<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\PlayerChromeCleaner;
use PHPUnit\Framework\TestCase;

final class PlayerChromeCleanerTest extends TestCase
{
    private const string PROSE =
        'For people who prefer to listen or are visually impaired or are multitasking, '
        . 'listening as they organize their rubber band collection.';

    private const string AUDIO = '<audio src="https://pub.test/api/v1/audio/upload/7adcfe96/src" preload="none">'
        . 'Audio playback is not supported on your browser.</audio>';

    private PlayerChromeCleaner $cleaner;

    protected function setUp(): void
    {
        $this->cleaner = new PlayerChromeCleaner();
    }

    public function testRemovesTheClockReadoutsBesideAPlayerWithTheirEmptiedRegion(): void
    {
        // Substack's audio embed (#786): readability keeps the player's time
        // labels as paragraphs inside the region the buttons left behind.
        $html = '<div><p>' . self::PROSE . '</p><div role="region"><p>0:00</p><p>-13:34</p></div>'
            . '<p>' . self::AUDIO . '</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('0:00', $clean);
        self::assertStringNotContainsString('13:34', $clean);
        self::assertStringNotContainsString('role="region"', $clean);
        self::assertStringContainsString('<audio', $clean);
        self::assertStringContainsString('rubber band', $clean);
    }

    public function testRemovesAClockReadoutWhenThePlayerItselfIsGone(): void
    {
        // sriramana (#786): a podcast page whose <audio> readability dropped
        // still leads with the dead readout.
        $html = '<div><p>Share from 0:00</p><div><p>0:00</p></div><p>' . self::PROSE . '</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('<p>0:00</p>', $clean);
        self::assertStringContainsString('Share from 0:00', $clean);
    }

    public function testRemovesReadoutsInHourFormAndSlashSeparatedPairs(): void
    {
        $html = '<div><p>1:02:03</p><p>0:00 / 1:02:03</p><p>0:00 | -13:34</p><p>' . self::PROSE . '</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('1:02:03', $clean);
        self::assertStringNotContainsString('13:34', $clean);
        self::assertStringContainsString('rubber band', $clean);
    }

    public function testKeepsAClockInsideProse(): void
    {
        $html = '<div><p>The recording ends at 13:34 sharp.</p><p>Um 21:15 Uhr endete der Einsatz.</p></div>';

        $clean = $this->clean($html);

        self::assertStringContainsString('13:34 sharp', $clean);
        self::assertStringContainsString('21:15 Uhr', $clean);
    }

    public function testKeepsARegionThatAlsoHoldsProse(): void
    {
        $html = '<div><div role="region"><p>0:00</p><p>' . self::PROSE . '</p></div></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('0:00', $clean);
        self::assertStringContainsString('role="region"', $clean);
        self::assertStringContainsString('rubber band', $clean);
    }

    public function testKeepsARegionThatStillHoldsMedia(): void
    {
        $html = '<div><div role="region"><p>0:00</p>' . self::AUDIO . '</div></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('0:00', $clean);
        self::assertStringContainsString('<audio', $clean);
    }

    public function testDoesNotSweepTheBodyItself(): void
    {
        self::assertStringContainsString('<body>', $this->clean('<p>0:00</p>'));
    }

    public function testRemovesAPlayerWithNoSourceInsteadOfGivingItControls(): void
    {
        // ZEIT (#903): the page's own <audio> carries the file only in data-src,
        // which the sanitizer strips, so restoring controls would arm a player
        // that can never play. A second, working player exists elsewhere.
        $html = '<div><audio data-src="https://zon-speechbert.test/full.mp3">'
            . 'Ihr Browser unterstützt keine Audio Dateien.</audio><p>' . self::PROSE . '</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('<audio', $clean);
        self::assertStringContainsString('rubber band', $clean);
    }

    public function testKeepsAPlayerWhoseFileComesFromASourceChild(): void
    {
        $html = '<div><video><source src="https://pub.test/c.mp4"></video></div>';

        $clean = $this->clean($html);

        self::assertStringContainsString('<video', $clean);
        self::assertStringContainsString('controls', $clean);
    }

    public function testGivesAScriptDrivenPlayerNativeControls(): void
    {
        // The page's own play button was script-driven and is gone; without
        // `controls` a bare <audio>/<video> renders as nothing at all.
        $html = '<div>' . self::AUDIO
            . '<video src="https://pub.test/clip.mp4" poster="https://pub.test/p.jpg"></video></div>';

        $clean = $this->clean($html);

        self::assertSame(2, substr_count($clean, ' controls'));
        self::assertStringContainsString('preload="none"', $clean);
    }

    public function testLeavesAPlayerThatAlreadyHasControlsAlone(): void
    {
        $html = '<div><audio controls src="https://pub.test/a.mp3"></audio></div>';

        self::assertSame(1, substr_count($this->clean($html), 'controls'));
    }

    // NPR renders a "copy this embed" widget beside the player — a label and a
    // <code> block showing the iframe snippet as escaped, literal text (#922).
    private const string NPR_EMBED_WIDGET =
        '<div><ul><li><p><label><b>Embed</b></label><b><code>'
        . '&lt;iframe src="https://www.npr.org/player/embed/g-s1-142210/nx-s1-mx-5960265-1" '
        . 'width="100%" height="290" frameborder="0" scrolling="no" title="NPR embedded audio player"&gt;'
        . '</code></b></p></li></ul></div>';

    public function testRemovesTheNprEmbedCodeWidgetAndItsEmptiedWrappers(): void
    {
        $html = '<div>' . self::AUDIO . self::NPR_EMBED_WIDGET . '<p>The real story text.</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('player/embed', $clean);
        self::assertStringNotContainsString('<code', $clean);
        self::assertStringNotContainsString('Embed', $clean);
        self::assertStringNotContainsString('<ul>', $clean);
        self::assertStringContainsString('<audio', $clean);
        self::assertStringContainsString('The real story text.', $clean);
    }

    public function testRemovesEveryEmbedWidgetNotJustTheFirst(): void
    {
        $html = '<div>' . self::NPR_EMBED_WIDGET . self::NPR_EMBED_WIDGET . '<p>Story.</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('player/embed', $clean);
        self::assertStringContainsString('Story.', $clean);
    }

    public function testRemovesAnEmbedCodeAndItsLabelFromABareParagraph(): void
    {
        // No list wrapper: the label and code sit directly in a paragraph, which
        // must go whole so the "Embed" label does not survive the code's removal.
        $html = '<div><p><b>Embed</b> <code>'
            . '&lt;iframe src="https://www.npr.org/player/embed/x/y"&gt;</code></p>'
            . '<p>Story.</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('player/embed', $clean);
        self::assertStringNotContainsString('Embed', $clean);
        self::assertStringContainsString('Story.', $clean);
    }

    public function testRemovesAnEmbedCodeAndItsLabelFromABareListItem(): void
    {
        // No paragraph inside the row: the <li> itself carries the label and code.
        $html = '<div><ul><li><b>Embed</b> <code>'
            . '&lt;iframe src="https://www.npr.org/player/embed/x/y"&gt;</code></li></ul>'
            . '<p>Story.</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('player/embed', $clean);
        self::assertStringNotContainsString('Embed', $clean);
        self::assertStringNotContainsString('<ul>', $clean);
        self::assertStringContainsString('Story.', $clean);
    }

    public function testRemovesAStandaloneEmbedCodeWithNoRowWrapper(): void
    {
        // Neither <li> nor <p> around it: only the code is dropped, and the span
        // it emptied with it, while the surrounding article stays.
        $html = '<div><span><code>'
            . '&lt;iframe src="https://www.npr.org/player/embed/x/y"&gt;</code></span>'
            . '<p>Story.</p></div>';

        $clean = $this->clean($html);

        self::assertStringNotContainsString('player/embed', $clean);
        self::assertStringNotContainsString('<code', $clean);
        self::assertStringNotContainsString('<span>', $clean);
        self::assertStringContainsString('Story.', $clean);
    }

    public function testLeavesAnUnrelatedCodeBlockAlone(): void
    {
        $html = '<div><p>Run <code>composer install</code> first.</p></div>';

        $clean = $this->clean($html);

        self::assertStringContainsString('composer install', $clean);
        self::assertStringContainsString('<code', $clean);
    }

    private function clean(string $html): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $this->cleaner->cleanIn($document);

        return $document->saveHtml();
    }
}
