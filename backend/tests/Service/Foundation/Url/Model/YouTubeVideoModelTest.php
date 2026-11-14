<?php

declare(strict_types=1);

namespace App\Tests\Service\Url\Model;

use App\Service\Url\Model\YouTubeVideoModel;
use PHPUnit\Framework\TestCase;

final class YouTubeVideoModelTest extends TestCase
{
    public function testAVideoPlaysInTheNocookiePlayer(): void
    {
        $video = YouTubeVideoModel::video('M1j_uRqKMKI');

        self::assertFalse($video->isShort);
        self::assertSame('https://www.youtube-nocookie.com/embed/M1j_uRqKMKI', $video->playerUrl());
    }

    public function testAShortPlaysInThePortraitPlayer(): void
    {
        $short = YouTubeVideoModel::short('GhUuOxrCato');

        self::assertTrue($short->isShort);
        self::assertSame('https://www.youtube-nocookie.com/embed/GhUuOxrCato#shorts', $short->playerUrl());
    }

    public function testThePosterIsTheVideoThumbnail(): void
    {
        self::assertSame(
            'https://i.ytimg.com/vi/GhUuOxrCato/hqdefault.jpg',
            YouTubeVideoModel::short('GhUuOxrCato')->posterUrl(),
        );
    }
}
