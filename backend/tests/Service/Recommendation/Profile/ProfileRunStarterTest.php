<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\Exception\ProfileConnectionMissingException;
use App\Service\Recommendation\Profile\ProfileRunStarter;
use App\Service\Recommendation\Run\Support\RunLogRetention;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Psr\Cache\CacheItemPoolInterface;

final class ProfileRunStarterTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // The limiter counts in a filesystem pool that outlives the test, so earlier runs' spend would trip a 429.
        /** @var CacheItemPoolInterface $rateLimiterCache */
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        $rateLimiterCache->clear();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-run-starter@example.test');
    }

    public function testAManualStartOpensAPendingManualRun(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');

        $profileRun = $this->starter()->startManually($this->owner);

        self::assertSame(ProfileRunTrigger::Manual, $profileRun->getTrigger());
        self::assertNotNull($profileRun->getId());
    }

    public function testASecondStartReturnsTheActiveRun(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $first = $this->starter()->start($this->owner, ProfileRunTrigger::Scheduled);

        $second = $this->starter()->startManually($this->owner);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(ProfileRunTrigger::Scheduled, $second->getTrigger());
    }

    public function testAStartWhileARunIsActiveReturnsItAndOpensNoSecond(): void
    {
        $first = $this->starter()->start($this->owner, ProfileRunTrigger::Scheduled);

        $second = $this->starter()->start($this->owner, ProfileRunTrigger::Recommendation);

        self::assertSame($first, $second);
        self::assertSame(ProfileRunTrigger::Scheduled, $second->getTrigger());
    }

    public function testAManualStartWithoutAUsableConnectionIsRefused(): void
    {
        $this->fixtures->seedReadyScoringSettings($this->owner);

        $this->expectException(ProfileConnectionMissingException::class);
        $this->starter()->startManually($this->owner);
    }

    public function testAStartTrimsTheLogToTheNewestProfileRuns(): void
    {
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $oldest = $this->finishedProfileRunWithOneRow();
        for ($index = 1; $index < RunLogRetention::RUNS; $index++) {
            $this->finishedProfileRunWithOneRow();
        }

        $this->starter()->start($this->owner, ProfileRunTrigger::Manual);

        self::assertSame([], $this->logs()->listForProfileRun($this->owner, $oldest->requireId()));
    }

    private function finishedProfileRunWithOneRow(): ProfileRun
    {
        $profileRun = new ProfileRun(
            $this->owner,
            ProfileRunTrigger::Manual,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $profileRun->fail('irrelevant', new \DateTimeImmutable('2026-10-03 09:00:01'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->persist(RecommendationRunLog::forProfileRun(
            $profileRun,
            1,
            '{}',
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        ));
        $this->entityManager->flush();

        return $profileRun;
    }

    private function starter(): ProfileRunStarter
    {
        /** @var ProfileRunStarter $starter */
        $starter = self::getContainer()->get(ProfileRunStarter::class);

        return $starter;
    }

    private function logs(): RecommendationRunLogRepository
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return $logs;
    }
}
