<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;

final class BackupLineRecords
{
    public static function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    public static function entry(): Entry
    {
        return new Entry(
            new Feed('https://one.example/feed.xml'),
            'guid-1',
            'https://one.example/a',
            'Article',
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            new \DateTimeImmutable('2026-08-03T00:00:00Z'),
        );
    }

    public static function subscription(Feed $feed): Subscription
    {
        return new Subscription(self::user(), $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    private function __construct()
    {
    }
}
