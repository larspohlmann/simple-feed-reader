<?php

declare(strict_types=1);

namespace App\Tests\Service\SubscriptionHistory;

use App\Entity\RetentionSetting;
use App\Entity\RetentionWindows;
use App\Service\Retention\RetentionSettings;
use App\Service\Retention\StoredRetentionSetting\StoredRetentionSettingInterface;
use App\Service\SubscriptionHistory\Model\TrackedSubscriptionModel;
use App\Service\SubscriptionHistory\RetentionHorizon;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class RetentionHorizonTest extends TestCase
{
    private const string TODAY = '2026-10-25';

    public function testAPlainFeedKeepsItsLastEntryDaysWholeAndNoMore(): void
    {
        $horizon = $this->horizon(10, 3)->asOf(self::TODAY);
        $plain = new TrackedSubscriptionModel(1, 1, '2026-01-01', false);

        self::assertTrue($horizon->isCountable($plain, '2026-10-16'));
        self::assertFalse($horizon->isCountable($plain, '2026-10-15'));
    }

    public function testASocialFeedKeepsItsLastSocialDaysWholeAndNoMore(): void
    {
        $horizon = $this->horizon(10, 3)->asOf(self::TODAY);
        $social = new TrackedSubscriptionModel(2, 2, '2026-01-01', true);

        self::assertTrue($horizon->isCountable($social, '2026-10-23'));
        self::assertFalse($horizon->isCountable($social, '2026-10-22'));
    }

    private function horizon(int $entryDays, int $socialDays): RetentionHorizon
    {
        $setting = new RetentionSetting();
        $setting->apply(new RetentionWindows($entryDays, $socialDays));
        $stored = $this->createStub(StoredRetentionSettingInterface::class);
        $stored->method('findSingleton')->willReturn($setting);

        return new RetentionHorizon(new RetentionSettings($stored, $this->createStub(EntityManagerInterface::class)));
    }
}
