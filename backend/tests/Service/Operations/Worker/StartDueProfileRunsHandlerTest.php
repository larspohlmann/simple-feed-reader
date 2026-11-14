<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\ProfileRun;
use App\Entity\ProfileSettingsValues;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Service\Worker\Handler\StartDueProfileRunsHandler;
use App\Service\Worker\Message\StartDueProfileRuns;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class StartDueProfileRunsHandlerTest extends DbTestCase
{
    use SeedsUsers;

    public function testAFiringStartsTheDueAccountsRunWithoutAdvancingIt(): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $owner = $this->user('start-due-profile-runs@example.test');
        (new RecommendationRunFixtures($this->entityManager, $cipher))->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $writer->saveProfileSettings($owner, new ProfileSettingsValues(12, null, 40, 80));

        /** @var StartDueProfileRunsHandler $handler */
        $handler = self::getContainer()->get(StartDueProfileRunsHandler::class);
        $handler(new StartDueProfileRuns());

        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);
        $started = $profileRuns->findActiveForUser($owner);
        self::assertNotNull($started);
        self::assertSame(ProfileRunTrigger::Scheduled, $started->getTrigger());
        self::assertSame(RunStatus::Pending, $started->getStatus());
    }
}
