<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\EntryAttachment;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Http\EntryJson;
use App\Repository\EntryListRow;
use PHPUnit\Framework\TestCase;

final class EntryJsonTest extends TestCase
{
    public function testEmitsMediaAndAttachmentsAsStructuredArrays(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            'https://example.com/ep',
            'Episode',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );
        $entry->setImage('https://i/lead.jpg', 800, 600);
        $entry->setMedia(
            [new EntryMedium('https://i/lead.jpg', 'image', 800, 600)],
            [new EntryAttachment('https://cdn/ep.mp3', 'audio/mpeg', 3723)],
        );

        $json = EntryJson::one($this->row($entry));

        self::assertSame(
            [['url' => 'https://i/lead.jpg', 'kind' => 'image', 'width' => 800, 'height' => 600]],
            $json['media'],
        );
        self::assertSame(
            [['url' => 'https://cdn/ep.mp3', 'mimeType' => 'audio/mpeg', 'durationInSeconds' => 3723]],
            $json['attachments'],
        );
    }

    public function testEmitsEmptyMediaListsWhenTheEntryHasNone(): void
    {
        $entry = new Entry(
            new Feed('https://example.com/feed'),
            'guid',
            null,
            'Plain',
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
            new \DateTimeImmutable('2026-09-07T00:00:00Z'),
        );

        $json = EntryJson::one($this->row($entry));

        self::assertSame([], $json['media']);
        self::assertSame([], $json['attachments']);
    }

    private function row(Entry $entry): EntryListRow
    {
        return new EntryListRow($entry, 1, 'Source', false, false, false, false, null, null);
    }
}
