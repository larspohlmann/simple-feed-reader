<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\ProfileSettingsValues;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class ProfileRunSweepTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testStartDueRunsOpensAScheduledRunPerDueAccount(): void
    {
        $first = $this->scheduledOwner('profile-sweep-a@example.test');
        $second = $this->scheduledOwner('profile-sweep-b@example.test');

        self::assertSame(2, $this->sweep()->startDueRuns());

        self::assertSame(ProfileRunTrigger::Scheduled, $this->profileRuns()->findActiveForUser($first)?->getTrigger());
        self::assertSame(ProfileRunTrigger::Scheduled, $this->profileRuns()->findActiveForUser($second)?->getTrigger());
    }

    public function testAdvanceTicksEveryActiveRunAndCountsThem(): void
    {
        $first = $this->scheduledOwner('profile-sweep-c@example.test');
        $second = $this->scheduledOwner('profile-sweep-d@example.test');
        $this->sweep()->startDueRuns();

        self::assertSame(2, $this->sweep()->activeRunCount());
        self::assertSame(2, $this->sweep()->advanceEveryActiveRun(TickDriver::Sweep));

        $this->entityManager->clear();
        self::assertSame(RunStatus::Completed, $this->profileRuns()->findLatestForUser($first)?->getStatus());
        self::assertSame(RunStatus::Completed, $this->profileRuns()->findLatestForUser($second)?->getStatus());
        self::assertSame(0, $this->sweep()->activeRunCount());
    }

    /** No history, so each run completes without a model call: the sweep is what is under test, not generation. */
    private function scheduledOwner(string $email): User
    {
        $owner = $this->user($email);
        $this->fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $writer->saveProfileSettings($owner, new ProfileSettingsValues(24, null, 40, 80));

        return $owner;
    }

    private function sweep(): ProfileRunSweep
    {
        /** @var ProfileRunSweep $sweep */
        $sweep = self::getContainer()->get(ProfileRunSweep::class);

        return $sweep;
    }

    private function profileRuns(): ProfileRunRepository
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);

        return $profileRuns;
    }
}
