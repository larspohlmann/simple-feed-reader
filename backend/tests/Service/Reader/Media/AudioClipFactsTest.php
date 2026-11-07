<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\AudioClipFacts;
use App\Service\Reader\Media\Model\AudioClipModel;
use App\Service\Reader\Media\PageFurniture;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class AudioClipFactsTest extends TestCase
{
    use ParsesHtml;

    private const string CLIP = 'https://ondemand.test/file/2026/11/07/episode.mp3';

    /** The first holder is a JSON blob; the play button beside it names the clip, its bare title the action. */
    public function testReadsTheClipFactsFromAnyElementHoldingItsUrl(): void
    {
        $html = '<body><script class="js-client-queries" data-json=\'{"url":"' . self::CLIP . '"}\'></script>'
            . '<button title="Audio abspielen" data-audio-src="' . self::CLIP . '?tracking=1"'
            . ' data-audiotitle="Ohne echte Lobby" data-audio-duration="2871"'
            . ' data-audio-download-tracking-title="Tracking copy"></button></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertEquals(new AudioClipModel(self::CLIP, 'Ohne echte Lobby', 2871), $facts);
    }

    public function testSkipsADurationThatIsNotWholeSeconds(): void
    {
        $html = '<body><div data-src="' . self::CLIP . '" data-duration="47:51"></div>'
            . '<a href="' . self::CLIP . '" data-media-duration="2871"></a></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertSame(2871, $facts->durationSeconds);
    }

    public function testTheFirstElementThatStatesAFactWins(): void
    {
        $html = '<body><div data-src="' . self::CLIP . '" data-title="First" data-duration="300"></div>'
            . '<a href="' . self::CLIP . '" data-title="Later" data-duration="120"></a></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertEquals(new AudioClipModel(self::CLIP, 'First', 300), $facts);
    }

    public function testTrimsAPaddedTitle(): void
    {
        $html = '<body><div data-src="' . self::CLIP . '" data-title="  Clip  "></div></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertSame('Clip', $facts->title);
    }

    public function testABlankTitleLeavesTheNextElementToNameTheClip(): void
    {
        $html = '<body><div data-src="' . self::CLIP . '" data-title="   "></div>'
            . '<a href="' . self::CLIP . '" data-title="Named"></a></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertSame('Named', $facts->title);
    }

    public function testKnowsNothingOfAClipNoElementDescribes(): void
    {
        $html = '<body><audio title="Play" src="' . self::CLIP . '"></audio>'
            . '<div data-audio-title="Another clip" data-src="https://ondemand.test/other.mp3"></div></body>';

        $facts = new AudioClipFacts(new PageFurniture())->of($this->document($html), self::CLIP);

        self::assertEquals(new AudioClipModel(self::CLIP), $facts);
    }
}
