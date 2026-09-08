<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Teaser;

use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Media\Teaser\TeaserPlayerMarkup;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class TeaserPlayerMarkupTest extends TestCase
{
    private TeaserPlayerMarkup $markup;
    private HTMLDocument $document;

    protected function setUp(): void
    {
        $this->markup = new TeaserPlayerMarkup();
        $this->document = HTMLDocument::createEmpty();
    }

    private function html(TeaserPlayer $teaser): string
    {
        $figure = $this->markup->figureFor($this->document, $teaser);

        return (string) $this->document->saveHtml($figure);
    }

    public function testAVideoTeaserIsAFigureWithAPosteredControllablePlayer(): void
    {
        $teaser = new TeaserPlayer(
            MediaKind::Video,
            'https://x.test/clip.mp4',
            'https://x.test/still.jpg',
            'The headline',
            'https://x.test/related.html',
        );

        $html = $this->html($teaser);

        self::assertStringContainsString('<figure class="reader-teaser">', $html);
        self::assertStringContainsString('<video', $html);
        self::assertStringContainsString('controls=""', $html);
        self::assertStringContainsString('preload="none"', $html);
        self::assertStringContainsString('src="https://x.test/clip.mp4"', $html);
        self::assertStringContainsString('poster="https://x.test/still.jpg"', $html);
        self::assertStringContainsString('<figcaption>', $html);
        self::assertStringContainsString('<a href="https://x.test/related.html">The headline</a>', $html);
    }

    public function testAnAudioTeaserKeepsItsStillAndLabelsItWithTheCaption(): void
    {
        $teaser = new TeaserPlayer(
            MediaKind::Audio,
            'https://x.test/ep.mp3',
            'https://x.test/still.jpg',
            'The headline',
            null,
        );

        $html = $this->html($teaser);

        self::assertStringContainsString('<img src="https://x.test/still.jpg" alt="The headline"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('<audio', $html);
        self::assertStringContainsString('src="https://x.test/ep.mp3"', $html);
        // The audio player carries no poster attribute of its own.
        self::assertStringNotContainsString('poster=', $html);
    }

    public function testAPlainCaptionHasNoLink(): void
    {
        $teaser = new TeaserPlayer(MediaKind::Video, 'https://x.test/c.mp4', 'https://x.test/s.jpg', 'Just text', null);

        $html = $this->html($teaser);

        self::assertStringContainsString('<figcaption>Just text</figcaption>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testNoCaptionMeansNoFigcaption(): void
    {
        $teaser = new TeaserPlayer(MediaKind::Video, 'https://x.test/c.mp4', 'https://x.test/s.jpg', null, null);

        self::assertStringNotContainsString('<figcaption', $this->html($teaser));
    }
}
