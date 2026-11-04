<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PreferencesTrendingTest extends TestCase
{
    public function testTrendingIsOnByDefault(): void
    {
        self::assertTrue($this->newPreferences()->isTrendingEnabled());
    }

    public function testItKeepsWhatItIsGiven(): void
    {
        $preferences = $this->newPreferences();

        $preferences->setTrendingEnabled(false);

        self::assertFalse($preferences->isTrendingEnabled());
    }

    private function newPreferences(): Preferences
    {
        return new User('reader@example.com', new \DateTimeImmutable('2026-11-04 09:00:00'))->getPreferences();
    }
}
