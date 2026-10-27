<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Entity\Feed;
use App\Entity\TranscriptParagraph;
use App\Enum\TranscriptFormat;
use App\Http\EntryTranscriptJson;
use PHPUnit\Framework\TestCase;

final class EntryTranscriptJsonTest extends TestCase
{
    public function testNoTranscriptIsNull(): void
    {
        self::assertNull(EntryTranscriptJson::of(null));
    }

    public function testATranscriptIsShapedAsPlainArrays(): void
    {
        $entry = new Entry(
            new Feed('https://pod.test/feed.xml'),
            'guid-1',
            'https://pod.test/ep1',
            'Episode 1',
            new \DateTimeImmutable('2026-10-27 06:00:00'),
            new \DateTimeImmutable('2026-10-27 05:00:00'),
        );
        $transcript = new EntryTranscript($entry, 'https://pod.test/ep1.vtt', TranscriptFormat::Vtt, 'de');
        $transcript->fill(
            [new TranscriptParagraph(3, 'Ann', 'Hi.'), new TranscriptParagraph(null, null, 'Ende.')],
            'x',
        );

        self::assertSame(
            [
                'language' => 'de',
                'paragraphs' => [
                    ['startSeconds' => 3, 'speaker' => 'Ann', 'text' => 'Hi.'],
                    ['startSeconds' => null, 'speaker' => null, 'text' => 'Ende.'],
                ],
            ],
            EntryTranscriptJson::of($transcript),
        );
    }
}
