<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\QuotedPostContent;
use App\Service\Backup\BackupLines;
use App\Service\Backup\Model\EntryCompanionsModel;
use PHPUnit\Framework\TestCase;

final class BackupLinesTest extends TestCase
{
    public function testAQuotedPostWithoutALinkCardIsWrittenWithNone(): void
    {
        $entry = new Entry(
            new Feed('https://one.example/feed.xml'),
            'guid-1',
            'https://one.example/a',
            'Article',
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
        );
        $entry->shareQuotedPost(
            new QuotedPostContent('https://bsky.app/profile/q/post/3k', 'Q', 'q', null, null, 'hi', [], null),
        );

        $line = json_decode(
            (new BackupLines())->entryLine($entry, 'https://one.example/feed.xml', new EntryCompanionsModel()),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($line);
        self::assertIsArray($line['quote']);
        self::assertArrayHasKey('linkCard', $line['quote']);
        self::assertNull($line['quote']['linkCard']);
    }
}
