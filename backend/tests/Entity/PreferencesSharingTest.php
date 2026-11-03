<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PreferencesSharingTest extends TestCase
{
    public function testSharingIsOnWithNothingHiddenByDefault(): void
    {
        $preferences = $this->newPreferences();

        self::assertTrue($preferences->isSharingEnabled());
        self::assertSame([], $preferences->getHiddenShareTargets());
    }

    public function testItKeepsWhatItIsGiven(): void
    {
        $preferences = $this->newPreferences();

        $preferences->setSharingEnabled(false);
        $preferences->setHiddenShareTargets(['facebook', 'x']);

        self::assertFalse($preferences->isSharingEnabled());
        self::assertSame(['facebook', 'x'], $preferences->getHiddenShareTargets());
    }

    private function newPreferences(): Preferences
    {
        return (new User('reader@example.com', new \DateTimeImmutable('2026-11-03 09:00:00')))->getPreferences();
    }
}
