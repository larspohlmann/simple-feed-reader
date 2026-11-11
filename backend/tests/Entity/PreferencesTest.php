<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PreferencesTest extends TestCase
{
    public function testPreferencesPointBackAtTheirUser(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        self::assertSame($user, $user->getPreferences()->getUser());
    }
}
