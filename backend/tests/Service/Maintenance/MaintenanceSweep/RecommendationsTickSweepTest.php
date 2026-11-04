<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

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

        self::assertSame(
            [
                'startedRuns' => 0,
                'advancedRuns' => 0,
                'activeRuns' => 0,
                'startedProfileRuns' => 1,
                'advancedProfileRuns' => 1,
                'activeProfileRuns' => 0,
            ],
            $report->outcome,
        );
    }

    public function testASkippedTickReportsNoRuns(): void
    {
        self::assertSame(
            [
                'startedRuns' => 0,
                'advancedRuns' => 0,
                'activeRuns' => 0,
                'startedProfileRuns' => 0,
                'advancedProfileRuns' => 0,
                'activeProfileRuns' => 0,
            ],
            $this->sweep()->skippedReport()->outcome,
        );
    }

    private function sweep(): RecommendationsTickSweep
    {
        $forYouSweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $forYouSweep);

        return new RecommendationsTickSweep($forYouSweep);
    }
}
