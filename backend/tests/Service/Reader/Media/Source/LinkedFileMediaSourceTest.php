<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\LinkedFileMediaSource;
use PHPUnit\Framework\TestCase;

final class LinkedFileMediaSourceTest extends TestCase
{
    private LinkedFileMediaSource $source;

    protected function setUp(): void
    {
        $providers = new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]);
        $this->source = new LinkedFileMediaSource(
            new MediaUrlKind(new DurableMediaUrl(), $providers),
            new MediaRelevance(),
        );
    }

    public function testFindsAnAudioFileBehindALink(): void
    {
        $html = '<body><a href="https://x.test/telescope-segment.mp3?t=progseg&amp;sc=siteplayer">Listen</a></body>';

        $found = $this->source->find($html, 'https://x.test/2026/08/30/roman-space-telescope');

        self::assertCount(1, $found);
        self::assertSame('https://x.test/telescope-segment.mp3', $found[0]->url);
    }

    public function testIgnoresAnOrdinaryArticleLink(): void
    {
        $html = '<body><a href="https://x.test/another-story">Read on</a></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testFindsTheSegmentInTheCapturedNprPage(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/npr-audio.html');
        self::assertIsString($html);

        $found = $this->source->find(
            $html,
            'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
        );

        self::assertNotSame([], $found);
        self::assertStringEndsWith('.mp3', $found[0]->url);
        self::assertStringNotContainsString('?', $found[0]->url);
    }
}
