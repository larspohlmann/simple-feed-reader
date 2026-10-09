<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Support;

use App\Service\Reader\Media\Support\YouTubeVideoId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YouTubeVideoIdTest extends TestCase
{
    #[DataProvider('youTubeComHosts')]
    public function testRecognisesAYouTubeComHost(string $host): void
    {
        self::assertTrue(YouTubeVideoId::isYouTubeComHost($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function youTubeComHosts(): iterable
    {
        yield 'bare' => ['youtube.com'];
        yield 'www' => ['www.youtube.com'];
        yield 'mobile' => ['m.youtube.com'];
        yield 'upper case' => ['WWW.YouTube.com'];
    }

    #[DataProvider('otherHosts')]
    public function testRejectsAnyOtherHost(string $host): void
    {
        self::assertFalse(YouTubeVideoId::isYouTubeComHost($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherHosts(): iterable
    {
        yield 'short link' => ['youtu.be'];
        yield 'nocookie' => ['www.youtube-nocookie.com'];
        yield 'look-alike' => ['notyoutube.com'];
    }
}
