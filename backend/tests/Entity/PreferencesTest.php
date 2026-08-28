<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Preferences;
use App\Entity\User;
use App\Service\Mail\Digest\DigestCadence;
use PHPUnit\Framework\TestCase;

final class PreferencesTest extends TestCase
{
    public function testANewUserHasPreferencesWithScrapingDisabled(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-08-02 10:00:00'));

        self::assertFalse($user->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testDigestDefaultsAreOffDailyEightMonday(): void
    {
        // Parenthesised new: PDepend (composer md) cannot parse a chained
        // `new Foo()->bar()` yet — keep the parens (repo note #183).
        $preferences = (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();

        self::assertFalse($preferences->isDigestEnabled());
        self::assertSame(DigestCadence::Daily, $preferences->getDigestCadence());
        self::assertSame(8, $preferences->getDigestSendHour());
        self::assertSame(1, $preferences->getDigestWeekday());
        self::assertNull($preferences->getDigestLastSentAt());
    }

    public function testDigestFieldsRoundTrip(): void
    {
        $preferences = (new User('a@b.example', new \DateTimeImmutable()))->getPreferences();
        $sentAt = new \DateTimeImmutable('2026-08-28 06:00:00');

        $preferences->setDigestEnabled(true);
        $preferences->setDigestCadence(DigestCadence::Weekly);
        $preferences->setDigestSendHour(20);
        $preferences->setDigestWeekday(6);
        $preferences->setDigestLastSentAt($sentAt);

        self::assertTrue($preferences->isDigestEnabled());
        self::assertSame(DigestCadence::Weekly, $preferences->getDigestCadence());
        self::assertSame(20, $preferences->getDigestSendHour());
        self::assertSame(6, $preferences->getDigestWeekday());
        self::assertSame($sentAt, $preferences->getDigestLastSentAt());
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
