<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\SemanticMediaSource;
use PHPUnit\Framework\TestCase;

final class SemanticMediaSourceTest extends TestCase
{
    private const string PROSE =
        'The paragraph the player followed on the source page, long enough to be prose.';

    private SemanticMediaSource $source;

    protected function setUp(): void
    {
        $this->source = new SemanticMediaSource(new MediaUrlKind(
            new DurableMediaUrl(),
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
        ));
    }

    public function testFindsAnAudioElement(): void
    {
        $found = $this->source->find('<body><audio src="https://x.test/a.mp3"></audio></body>', 'https://x.test/a');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
    }

    public function testFindsAVideoWithSourceChildrenAndKeepsItsPoster(): void
    {
        $html = '<body><video poster="https://x.test/p.jpg"><source src="https://x.test/v.mp4" type="video/mp4">'
            . '</video></body>';

        $found = $this->source->find($html, 'https://x.test/a');

        self::assertCount(1, $found);
        self::assertSame('https://x.test/p.jpg', $found[0]->posterUrl);
    }

    public function testSkipsAVideoWithNoPoster(): void
    {
        $html = '<body><video><source src="https://x.test/v.mp4" type="video/mp4"></video></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testSkipsAnHlsSource(): void
    {
        $html = '<body><video poster="https://x.test/p.jpg"><source src="https://x.test/master.m3u8"></video></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testSkipsAVideoWithEmptyPoster(): void
    {
        $html = '<body><video poster=""><source src="https://x.test/v.mp4" type="video/mp4"></video></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testSkipsAVideoSourceThatResolvesToAnEmbedInsteadOfANativeFile(): void
    {
        $html = '<body><video poster="https://x.test/p.jpg">'
            . '<source src="https://www.youtube.com/embed/aaaaaaaaaaa"></video></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testNamesTheProseBlockThePlayerFollows(): void
    {
        $html = '<body><p>' . self::PROSE . '</p><audio src="https://x.test/a.mp3"></audio></body>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertSame(self::PROSE, $found[0]->precedingText);
    }

    public function testSkipsAPlayerInsideAnAside(): void
    {
        $html = '<body><aside><audio src="https://x.test/teaser.mp3"></audio></aside>'
            . '<audio src="https://x.test/a.mp3"></audio></body>';

        $found = $this->source->find($html, 'https://x.test/a.html');

        self::assertCount(1, $found);
        self::assertSame('https://x.test/a.mp3', $found[0]->url);
    }
}
