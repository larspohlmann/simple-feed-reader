<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Content\Reader\BodyCleaning\BodyCleaningStep\AudioPlayerNormalizer;
use App\Service\Content\Reader\Media\AudioPlayerMarkup;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class AudioPlayerNormalizerTest extends TestCase
{
    use ParsesHtml;

    public function testCollapsesASourceListToTheFirstPlayableAudio(): void
    {
        $out = $this->normalize('<audio controls autoplay loop>'
            . '<source src="https://x.test/a.webm" type="video/webm">'
            . '<source src="https://x.test/a.mp3?_=1" type="audio/mpeg">'
            . '<source src="https://x.test/a.ogg"></audio>');

        self::assertStringContainsString(
            '<audio controls="" preload="none" src="https://x.test/a.mp3?_=1"></audio>',
            $out,
        );
    }

    public function testTheElementsOwnSourceWinsOverItsChildren(): void
    {
        $out = $this->normalize('<audio src="https://x.test/own.mp3"><source src="https://x.test/child.mp3"></audio>');

        self::assertStringContainsString('src="https://x.test/own.mp3"', $out);
        self::assertStringNotContainsString('child.mp3', $out);
    }

    public function testKeepsTheTitleAWholeDurationAndTheNarrationMark(): void
    {
        $out = $this->normalize('<audio class="reader-narration" title="Clip" src="https://x.test/full.mp3">'
            . '<time datetime="PT300S">5:00</time></audio>');

        self::assertStringContainsString(
            '<audio controls="" preload="none" src="https://x.test/full.mp3" title="Clip" class="reader-narration">'
            . '<time datetime="PT300S">5:00</time></audio>',
            $out,
        );
    }

    public function testDropsADurationThatIsNotWholeSeconds(): void
    {
        $out = $this->normalize('<audio src="https://x.test/a.mp3"><time datetime="PT5M">5:00</time></audio>');

        self::assertStringContainsString('<audio controls="" preload="none" src="https://x.test/a.mp3"></audio>', $out);
    }

    public function testReadsTheDurationOnlyFromItsOwnTime(): void
    {
        $out = $this->normalize('<audio src="https://x.test/a.mp3"><span><time datetime="PT300S">5:00</time></span>'
            . '</audio>');

        self::assertStringNotContainsString('<time', $out);
    }

    public function testAnUntypedSourceCountsAsAudio(): void
    {
        $out = $this->normalize('<audio><source src="https://x.test/a.webm" type="video/webm">'
            . '<source src="https://x.test/untyped"></audio>');

        self::assertStringContainsString('src="https://x.test/untyped"', $out);
    }

    public function testIgnoresASourceNestedDeeperThanTheElement(): void
    {
        $out = $this->normalize('<audio><span><source src="https://x.test/nested.mp3" type="audio/mpeg"></span>'
            . '<source src="https://x.test/good.mp3" type="audio/mpeg"></audio>');

        self::assertStringContainsString('src="https://x.test/good.mp3"', $out);
        self::assertStringNotContainsString('nested.mp3', $out);
    }

    public function testTrimsAPaddedSource(): void
    {
        $out = $this->normalize('<audio src="  https://x.test/own.mp3 "></audio>');

        self::assertStringContainsString('src="https://x.test/own.mp3">', $out);
    }

    public function testTrimsAPaddedChildSource(): void
    {
        $out = $this->normalize('<audio><source src=" https://x.test/child.mp3 " type="audio/mpeg"></audio>');

        self::assertStringContainsString('src="https://x.test/child.mp3">', $out);
    }

    public function testRemovesALiveStream(): void
    {
        $out = $this->normalize('<p>Intro</p><audio controls src="https://radio.test/stream.mp3?x=1"></audio>');

        self::assertStringNotContainsString('<audio', $out);
        self::assertStringContainsString('Intro', $out);
    }

    public function testRebuildsThePlayersAfterARemovedOne(): void
    {
        $out = $this->normalize('<audio controls></audio><audio autoplay src="https://x.test/a.mp3"></audio>');

        self::assertStringContainsString('<audio controls="" preload="none" src="https://x.test/a.mp3"></audio>', $out);
    }

    public function testRemovesAPlayerWithNothingToPlay(): void
    {
        $out = $this->normalize('<audio controls>Your browser cannot play this.</audio>');

        self::assertStringNotContainsString('<audio', $out);
    }

    public function testAPlayerAlreadyInShapeIsUnchanged(): void
    {
        $canonical = '<audio controls="" preload="none" src="https://x.test/a.mp3"'
            . ' title="Clip"><time datetime="PT300S">5:00</time></audio>';

        self::assertStringContainsString($canonical, $this->normalize($canonical));
    }

    private function normalize(string $html): string
    {
        $document = $this->document($html);
        new AudioPlayerNormalizer(new AudioPlayerMarkup())->cleanIn(BodyCleaningPasses::over($document));

        return $document->saveHtml();
    }
}
