<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Source\DeutschlandradioAudioSource;
use PHPUnit\Framework\TestCase;

final class DeutschlandradioAudioSourceTest extends TestCase
{
    private DeutschlandradioAudioSource $source;

    protected function setUp(): void
    {
        $this->source = new DeutschlandradioAudioSource(new DurableMediaUrl());
    }

    private const string URL = 'https://www.deutschlandfunkkultur.de/bildung-100.html';

    public function testTakesTheFirstArticleAudio(): void
    {
        $html = '<html><body>'
            . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/bildung.mp3"></div>'
            . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/teaser.mp3"></div>'
            . '</body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
        self::assertStringEndsWith('bildung.mp3', $found[0]->url);
    }

    public function testSkipsTheLiveStreamAndTakesTheEpisode(): void
    {
        $html = '<html><body>'
            . '<div data-audio-src="https://st01.sslstream.dlf.de/dlf/01/128/mp3/stream.mp3"></div>'
            . '<div data-audio-src="https://ondemand-mp3.dradio.de/file/dradio/2026/08/bildung.mp3"></div>'
            . '</body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertStringEndsWith('bildung.mp3', $found[0]->url);
    }

    public function testIgnoresAnotherHost(): void
    {
        $html = '<html><body><div data-audio-src="https://ondemand-mp3.dradio.de/a.mp3"></div></body></html>';

        self::assertSame([], $this->source->find($html, 'https://www.spiegel.de/x.html'));
    }

    public function testFindsNothingWhenThePageHasNoAudio(): void
    {
        self::assertSame([], $this->source->find('<html><body><p>text</p></body></html>', self::URL));
    }
}
