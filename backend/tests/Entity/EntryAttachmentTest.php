<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EntryAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryAttachmentTest extends TestCase
{
    /** @return iterable<string, array{EntryAttachment, bool}> */
    public static function attachments(): iterable
    {
        yield 'audio mime type' => [new EntryAttachment('https://pod.test/episode', 'audio/mpeg'), true];
        yield 'upper-case audio mime type' => [new EntryAttachment('https://pod.test/episode', 'Audio/MPEG'), true];
        yield 'video mime type' => [new EntryAttachment('https://pod.test/episode.mp3', 'video/mp4'), false];
        yield 'no mime type, audio extension' => [new EntryAttachment('https://pod.test/episode.MP3?x=1'), true];
        yield 'no mime type, document extension' => [new EntryAttachment('https://pod.test/programme.pdf'), false];
        yield 'no mime type, no extension' => [new EntryAttachment('https://pod.test/episode'), false];
    }

    #[DataProvider('attachments')]
    public function testIsAudioByItsMimeTypeElseItsExtension(EntryAttachment $attachment, bool $isAudio): void
    {
        self::assertSame($isAudio, $attachment->isAudio());
    }
}
