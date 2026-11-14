<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\Recommendation\Run\Model\ForYouSweepReportModel;
use App\Entity\ProfileSettingsValues;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Maintenance\MaintenanceSweep\RecommendationsTickSweep;
use App\Service\Recommendation\Run\ForYouSweep;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class RecommendationsTickSweepTest extends DbTestCase
{
    use SeedsUsers;

    public function testAdvancesTheRunsOnceEvenAfterTheDeadline(): void
    {
        $owner = $this->user('tick-sweep-recommendations@example.test');
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        new RecommendationRunFixtures($this->entityManager, $cipher)->seedReadyAiSettings($owner);
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $writer->saveProfileSettings($owner, new ProfileSettingsValues(6, null, 40, 80));

        $report = $this->sweep()->run(new \DateTimeImmutable('2000-01-01 00:00:00'));

        self::assertEquals(new ForYouSweepReportModel(0, 0, 0, 1, 1, 0), $report);
    }

    public function testAnIdleTickReportsNoRuns(): void
    {
        self::assertEquals(new ForYouSweepReportModel(0, 0, 0, 0, 0, 0), $this->sweep()->idleReport());
    }

    private function sweep(): RecommendationsTickSweep
    {
        $forYouSweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $forYouSweep);

        return new RecommendationsTickSweep($forYouSweep);
    }
}
