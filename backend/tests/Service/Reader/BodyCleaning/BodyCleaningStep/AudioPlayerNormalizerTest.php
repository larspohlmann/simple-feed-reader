<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\AudioPlayerNormalizer;
use App\Service\Reader\Media\AudioPlayerMarkup;
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
        $out = $this->normalize('<audio class="reader-narration" title="Clip" data-duration="300"'
            . ' src="https://x.test/full.mp3"></audio>');

        self::assertStringContainsString(
            '<audio controls="" preload="none" src="https://x.test/full.mp3" title="Clip" data-duration="300"'
            . ' class="reader-narration"></audio>',
            $out,
        );
    }

    public function testDropsADurationThatIsNotWholeSeconds(): void
    {
        $out = $this->normalize('<audio data-duration="5:00" src="https://x.test/a.mp3"></audio>');

        self::assertStringNotContainsString('data-duration', $out);
    }

    public function testRemovesALiveStream(): void
    {
        $out = $this->normalize('<p>Intro</p><audio controls src="https://radio.test/stream.mp3?x=1"></audio>');

        self::assertStringNotContainsString('<audio', $out);
        self::assertStringContainsString('Intro', $out);
    }

    public function testRemovesAPlayerWithNothingToPlay(): void
    {
        $out = $this->normalize('<audio controls>Your browser cannot play this.</audio>');

        self::assertStringNotContainsString('<audio', $out);
    }

    public function testAPlayerAlreadyInShapeIsUnchanged(): void
    {
        $canonical = '<audio controls="" preload="none" src="https://x.test/a.mp3"'
            . ' title="Clip" data-duration="300"></audio>';

        self::assertStringContainsString($canonical, $this->normalize($canonical));
    }

    private function normalize(string $html): string
    {
        $document = $this->document($html);
        new AudioPlayerNormalizer(new AudioPlayerMarkup())->cleanIn(BodyCleaningPasses::over($document));

        return $document->saveHtml();
    }
}
