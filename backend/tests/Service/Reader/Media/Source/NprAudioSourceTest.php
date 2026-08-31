<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Source\NprAudioSource;
use PHPUnit\Framework\TestCase;

final class NprAudioSourceTest extends TestCase
{
    private NprAudioSource $source;

    protected function setUp(): void
    {
        $this->source = new NprAudioSource(new DurableMediaUrl());
    }

    private const string URL = 'https://www.npr.org/2026/08/30/nx-s1-5948814/roman-space-telescope';

    public function testStripsTheAnalyticsQuery(): void
    {
        $html = '<html><body><audio src="https://ondemand.npr.org/anon.npr-mp3/npr/wesun/dark_energy.mp3'
            . '?t=progseg&amp;sc=siteplayer&amp;aw_0_1st.playerid=siteplayer"></audio></body></html>';

        $found = $this->source->find($html, self::URL);

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Audio, $found[0]->kind);
        self::assertSame('https://ondemand.npr.org/anon.npr-mp3/npr/wesun/dark_energy.mp3', $found[0]->url);
    }

    public function testIgnoresAnotherHost(): void
    {
        $html = '<html><body><audio src="https://ondemand.npr.org/anon.npr-mp3/a.mp3"></audio></body></html>';

        self::assertSame([], $this->source->find($html, 'https://www.spiegel.de/x.html'));
    }

    public function testIgnoresAnNprUrlOutsideTheAnonymousPath(): void
    {
        $html = '<html><body><audio src="https://ondemand.npr.org/members-only/a.mp3"></audio></body></html>';

        self::assertSame([], $this->source->find($html, self::URL));
    }

    public function testFindsTheAudioInTheCapturedPage(): void
    {
        $html = file_get_contents(__DIR__ . '/../../../../Fixtures/reader/media/npr-audio.html');
        self::assertIsString($html);

        $found = $this->source->find(
            $html,
            'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
        );

        self::assertCount(1, $found);
        self::assertStringContainsString('.mp3', $found[0]->url);
    }
}
