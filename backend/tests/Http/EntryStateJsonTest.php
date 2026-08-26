<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Http\EntryStateJson;
use PHPUnit\Framework\TestCase;

final class EntryStateJsonTest extends TestCase
{
    public function testExposesTheHiddenContract(): void
    {
        $when = new \DateTimeImmutable('2026-08-26T12:00:00+00:00');
        $state = new EntryState(
            new User('json@example.com', $when),
            new Entry(new Feed('https://json.example/feed.xml'), 'json', null, 'JSON', $when, $when),
        );
        $state->hide($when);
        $state->markViewed($when);
        $entryId = 483;

        $json = EntryStateJson::one($state, $entryId);

        self::assertSame([
            'entryId' => $entryId,
            'isHidden' => true,
            'isFavorite' => false,
            'isKept' => false,
            'hiddenAt' => $when->format(\DateTimeInterface::ATOM),
            'isViewed' => true,
            'viewedAt' => $when->format(\DateTimeInterface::ATOM),
        ], $json);
    }
}
