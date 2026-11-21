<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PlayableMediaFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlayableMediaFileTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function audioExtensions(): iterable
    {
        foreach (['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac'] as $extension) {
            yield $extension => [$extension];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function videoExtensions(): iterable
    {
        foreach (['mp4', 'm4v', 'webm', 'mov'] as $extension) {
            yield $extension => [$extension];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unplayableExtensions(): iterable
    {
        foreach (['wma', 'mkv', 'avi', 'ogv', ''] as $extension) {
            yield $extension === '' ? 'none' : $extension => [$extension];
        }
    }

    #[DataProvider('audioExtensions')]
    public function testRecognisesBrowserPlayableAudio(string $extension): void
    {
        self::assertTrue(PlayableMediaFile::isAudioExtension($extension));
        self::assertFalse(PlayableMediaFile::isVideoExtension($extension));
    }

    #[DataProvider('videoExtensions')]
    public function testRecognisesBrowserPlayableVideo(string $extension): void
    {
        self::assertTrue(PlayableMediaFile::isVideoExtension($extension));
        self::assertFalse(PlayableMediaFile::isAudioExtension($extension));
    }

    #[DataProvider('unplayableExtensions')]
    public function testRefusesMediaBrowsersDoNotPlay(string $extension): void
    {
        self::assertFalse(PlayableMediaFile::isAudioExtension($extension));
        self::assertFalse(PlayableMediaFile::isVideoExtension($extension));
    }

    public function testExtractsTheLowerCasedExtensionOfTheUrlPath(): void
    {
        self::assertSame('mp3', PlayableMediaFile::extractExtension('https://x.test/a/Ep.MP3?v=1'));
        self::assertSame('', PlayableMediaFile::extractExtension('https://x.test/episode'));
    }
}
