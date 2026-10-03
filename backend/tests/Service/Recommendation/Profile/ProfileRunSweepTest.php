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
use App\Service\Recommendation\Profile\DueProfileRunFinder;
use App\Service\Recommendation\Profile\ProfileRunAdvancer;
use App\Service\Recommendation\Profile\ProfileRunStarter;
use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\RecordingLogger;
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

    /** The stub chat client has no reply queued, so the call throws past the tick into the sweep's floor. */
    public function testAnUnexpectedFailureIsLoggedWithItsRunAndTheSweepCarriesOn(): void
    {
        $owner = $this->user('profile-sweep-floor@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $this->fixtures->seedFavorites($owner, 'maps', 1);
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
        $logger = new RecordingLogger();

        self::assertSame(1, $this->sweepLoggingTo($logger)->advanceEveryActiveRun(TickDriver::Sweep));

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame($profileRun->getId(), $logger->records[0]['context']['profileRunId']);
        self::assertInstanceOf(\LogicException::class, $logger->records[0]['context']['exception']);
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

    private function sweepLoggingTo(RecordingLogger $logger): ProfileRunSweep
    {
        $container = self::getContainer();
        /** @var DueProfileRunFinder $finder */
        $finder = $container->get(DueProfileRunFinder::class);
        /** @var ProfileRunStarter $starter */
        $starter = $container->get(ProfileRunStarter::class);
        /** @var ProfileRunAdvancer $advancer */
        $advancer = $container->get(ProfileRunAdvancer::class);

        return new ProfileRunSweep($finder, $starter, $advancer, $this->profileRuns(), $logger);
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
