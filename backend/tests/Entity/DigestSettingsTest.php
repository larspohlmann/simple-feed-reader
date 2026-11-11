<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DigestSettings;
use App\Entity\User;
use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use PHPUnit\Framework\TestCase;

final class DigestSettingsTest extends TestCase
{
    public function testTheDigestIsOffDailyHtmlAtEightOnMondayAndNeverSentByDefault(): void
    {
        $settings = $this->newSettings();

        self::assertFalse($settings->isEnabled());
        self::assertSame(DigestCadence::Daily, $settings->getCadence());
        self::assertSame(DigestFormat::Html, $settings->getFormat());
        self::assertSame(8, $settings->getSendHour());
        self::assertSame(1, $settings->getWeekday());
        self::assertNull($settings->getLastSentAt());
    }

    public function testItKeepsWhatItIsGiven(): void
    {
        $settings = $this->newSettings();
        $sentAt = new \DateTimeImmutable('2026-08-28 06:00:00');

        $settings->setEnabled(true);
        $settings->setCadence(DigestCadence::Weekly);
        $settings->setFormat(DigestFormat::Text);
        $settings->setSendHour(20);
        $settings->setWeekday(6);
        $settings->setLastSentAt($sentAt);

        self::assertTrue($settings->isEnabled());
        self::assertSame(DigestCadence::Weekly, $settings->getCadence());
        self::assertSame(DigestFormat::Text, $settings->getFormat());
        self::assertSame(20, $settings->getSendHour());
        self::assertSame(6, $settings->getWeekday());
        self::assertSame($sentAt, $settings->getLastSentAt());
    }

    public function testItPointsBackAtItsUser(): void
    {
        $user = new User('reader@example.com', new \DateTimeImmutable('2026-11-03 09:00:00'));

        self::assertSame($user, new DigestSettings($user)->getUser());
    }

    private function newSettings(): DigestSettings
    {
        return new DigestSettings(new User('reader@example.com', new \DateTimeImmutable('2026-11-03 09:00:00')));
    }
}
