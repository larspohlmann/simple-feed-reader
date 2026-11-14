<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\AudioClipFacts;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class AudioClipFactsTest extends TestCase
{
    use ParsesHtml;

    private const string CLIP = 'https://ondemand.test/file/2026/11/07/episode.mp3';

    /** The first holder is a JSON blob; the play button beside it names the clip, its bare title the action. */
    public function testReadsTheClipFactsFromAnyHolder(): void
    {
        $holders = $this->holders(
            '<script class="js-client-queries" data-json=\'{"url":"' . self::CLIP . '"}\'></script>'
            . '<button title="Audio abspielen" data-audio-src="' . self::CLIP . '?tracking=1"'
            . ' data-audiotitle="Ohne echte Lobby" data-audio-duration="2871"'
            . ' data-audio-download-tracking-title="Tracking copy"></button>',
        );

        self::assertSame('Ohne echte Lobby', new AudioClipFacts()->titleOf($holders));
        self::assertSame(2871, new AudioClipFacts()->durationOf($holders));
    }

    public function testReadsAClockDuration(): void
    {
        $holders = $this->holders('<div data-duration="47:51"></div>');

        self::assertSame(2871, new AudioClipFacts()->durationOf($holders));
    }

    public function testSkipsADurationThatIsNoDuration(): void
    {
        $holders = $this->holders('<div data-duration="abc"></div><a data-media-duration="300"></a>');

        self::assertSame(300, new AudioClipFacts()->durationOf($holders));
    }

    public function testTheFirstHolderThatStatesAFactWins(): void
    {
        $holders = $this->holders('<div data-title="First" data-duration="300"></div>'
            . '<a data-title="Later" data-duration="120"></a>');

        self::assertSame('First', new AudioClipFacts()->titleOf($holders));
        self::assertSame(300, new AudioClipFacts()->durationOf($holders));
    }

    public function testTrimsAPaddedTitle(): void
    {
        $holders = $this->holders('<div data-title="  Clip  "></div>');

        self::assertSame('Clip', new AudioClipFacts()->titleOf($holders));
    }

    public function testABlankTitleLeavesTheNextHolderToNameTheClip(): void
    {
        $holders = $this->holders('<div data-title="   "></div><a data-title="Named"></a>');

        self::assertSame('Named', new AudioClipFacts()->titleOf($holders));
    }

    public function testKnowsNothingAHolderDoesNotState(): void
    {
        $holders = $this->holders('<audio title="Play" src="' . self::CLIP . '"></audio>');

        self::assertNull(new AudioClipFacts()->titleOf($holders));
        self::assertNull(new AudioClipFacts()->durationOf($holders));
    }

    /** @return list<Element> */
    private function holders(string $bodyHtml): array
    {
        $document = $this->document('<body>' . $bodyHtml . '</body>');

        return array_values(iterator_to_array($document->querySelectorAll('body > *')));
    }
}
