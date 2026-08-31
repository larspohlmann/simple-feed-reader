<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Source\ArdVideoSource;
use PHPUnit\Framework\TestCase;

final class ArdVideoSourceTest extends TestCase
{
    private ArdVideoSource $source;

    protected function setUp(): void
    {
        $this->source = new ArdVideoSource(new DurableMediaUrl());
    }

    private const string URL = 'https://www.tagesschau.de/ausland/beispiel-100.html';

    public function testFindsTheProgressiveVideoWithItsPoster(): void
    {
        $html = '<html><head><meta property="og:image" content="https://images.tagesschau.de/p.jpg"></head>'
            . '<body><script type="application/json">{"streams":['
            . '"https://tagesschau-progressive.ard-mcdn.de/v/webl.mp4",'
            . '"https://tagesschau-progressive.ard-mcdn.de/v/webxxl.mp4"]}</script></body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Video, $found[0]->kind);
        self::assertSame('https://images.tagesschau.de/p.jpg', $found[0]->posterUrl);
    }

    /** No poster, no candidate: a posterless video rots into a dead frame. */
    public function testDropsTheVideoWhenThePageOffersNoPoster(): void
    {
        $html = '<html><head></head><body><script type="application/json">'
            . '{"streams":["https://tagesschau-progressive.ard-mcdn.de/v/webl.mp4"]}</script></body></html>';

        self::assertSame([], $this->source->find($html, self::URL));
    }

    public function testIgnoresAnHlsPlaylist(): void
    {
        $html = '<html><head><meta property="og:image" content="https://images.tagesschau.de/p.jpg"></head>'
            . '<body><script type="application/json">'
            . '{"streams":["https://tagesschau-progressive.ard-mcdn.de/v/master.m3u8"]}</script></body></html>';

        self::assertSame([], $this->source->find($html, self::URL));
    }

    public function testIgnoresAnotherHost(): void
    {
        $html = '<html><head><meta property="og:image" content="https://x.test/p.jpg"></head>'
            . '<body><script>"https://tagesschau-progressive.ard-mcdn.de/v/webl.mp4"</script></body></html>';

        self::assertSame([], $this->source->find($html, 'https://www.spiegel.de/x.html'));
    }

    public function testFindsTheVideoInTheCapturedPage(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/ard-video.html');
        self::assertIsString($html);

        $found = $this->source->find($html, 'https://www.tagesschau.de/video/video-1640158.html');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Video, $found[0]->kind);
        self::assertStringContainsString('.mp4', $found[0]->url);
        self::assertStringContainsString('webxxl', $found[0]->url);
        self::assertNotNull($found[0]->posterUrl);
    }
}
