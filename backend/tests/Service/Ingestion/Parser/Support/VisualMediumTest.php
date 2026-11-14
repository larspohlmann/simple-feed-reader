<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Support;

use App\Service\Parser\Support\VisualMedium;
use PHPUnit\Framework\TestCase;

final class VisualMediumTest extends TestCase
{
    public function testAnImageFile(): void
    {
        $medium = VisualMedium::at('https://i.example/a.PNG?x=1');

        self::assertNotNull($medium);
        self::assertSame('image', $medium->kind);
        self::assertSame('https://i.example/a.PNG?x=1', $medium->url);
    }

    public function testAVideoFileWithItsPoster(): void
    {
        $medium = VisualMedium::at('https://v.example/clip.mp4', 'https://v.example/poster.jpg');

        self::assertNotNull($medium);
        self::assertSame('video', $medium->kind);
        self::assertSame('https://v.example/poster.jpg', $medium->previewImageUrl);
    }

    public function testNoPageNoAudioNoPlainHttp(): void
    {
        self::assertNull(VisualMedium::at('https://example.com/story'));
        self::assertNull(VisualMedium::at('https://example.com/episode.mp3'));
        self::assertNull(VisualMedium::at('http://example.com/a.jpg'));
    }
}
