<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\UserSettingsRow;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

final class UserSettingsRowTest extends TestCase
{
    use AssignsEntityIds;

    public function testAPresentRowIsReturned(): void
    {
        $row = new \stdClass();

        self::assertSame($row, UserSettingsRow::required($row, $this->user()));
    }

    public function testAMissingRowIsCorruption(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('User 7 has no settings row');

        UserSettingsRow::required(null, $this->user());
    }

    private function user(): User
    {
        return self::withId(new User('reader@example.com', new \DateTimeImmutable('2026-11-11 09:00:00')), 7);
    }
}
