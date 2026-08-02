<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PreferencesTest extends TestCase
{
    public function testANewUserHasPreferencesWithScrapingDisabled(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        self::assertFalse($user->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testPreferencesPointBackAtTheirUser(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        self::assertSame($user, $user->getPreferences()->getUser());
    }

    public function testScrapeFallbackCanBeEnabled(): void
    {
        $preferences = new Preferences(
            new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00')),
        );

        $preferences->setScrapeFallbackEnabled(true);

        self::assertTrue($preferences->isScrapeFallbackEnabled());
    }
}
