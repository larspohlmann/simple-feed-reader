<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\NotificationPostKeys;
use PHPUnit\Framework\TestCase;

final class NotificationPostKeysTest extends TestCase
{
    public function testHashesFollowCandidateOrderWithoutGapsOrRepeats(): void
    {
        self::assertSame(['p', 'r'], new NotificationPostKeys('p', null, 'r')->hashes());
        self::assertSame(['p'], new NotificationPostKeys('p', 'p')->hashes());
        self::assertSame(['a', 'p', 'r'], new NotificationPostKeys('a', 'p', 'r')->hashes());
        self::assertSame([], new NotificationPostKeys()->hashes());
    }

    public function testPickEntryIdTakesTheFirstPresentHashInPostAnsweredRootOrder(): void
    {
        $keys = new NotificationPostKeys('p', 'a', 'r');

        self::assertSame(1, $keys->pickEntryId(['r' => 3, 'a' => 2, 'p' => 1]));
        self::assertSame(2, $keys->pickEntryId(['r' => 3, 'a' => 2]));
        self::assertSame(3, $keys->pickEntryId(['r' => 3]));
    }

    public function testPickEntryIdIsNullWhenNoHashIsPresent(): void
    {
        self::assertNull(new NotificationPostKeys('p', 'a', 'r')->pickEntryId(['x' => 1]));
        self::assertNull(new NotificationPostKeys()->pickEntryId(['x' => 1]));
    }

    public function testWithAnsweredKeepsTheOtherHashes(): void
    {
        self::assertEquals(
            new NotificationPostKeys('p', 'a', 'r'),
            new NotificationPostKeys('p', null, 'r')->withAnswered('a'),
        );
    }
}
