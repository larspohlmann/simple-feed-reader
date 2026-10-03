<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\ProfileSettingsValues;
use App\Entity\User;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\DueProfileRunFinder;
use App\Service\Recommendation\Profile\ProfileConnections;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

final class DueProfileRunFinderTest extends DbTestCase
{
    use SeedsUsers;

    private const string NOW = '2026-10-03 12:00:00';

    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
    }

    public function testDueOneIntervalAfterTheNewestRunEvenWhenItFailed(): void
    {
        $owner = $this->scheduledOwner('profile-due-failed@example.test', 6);
        $this->failedRunAt($owner, '2026-10-03 05:30:00');

        self::assertSame(['profile-due-failed@example.test'], $this->dueEmails());
    }

    public function testNotDueInsideTheInterval(): void
    {
        $owner = $this->scheduledOwner('profile-due-fresh@example.test', 6);
        $this->completedRunAt($owner, '2026-10-03 06:30:00');

        self::assertSame([], $this->dueEmails());
    }

    public function testDueExactlyOneIntervalLater(): void
    {
        $owner = $this->scheduledOwner('profile-due-boundary@example.test', 6);
        $this->completedRunAt($owner, '2026-10-03 06:00:00');

        self::assertSame(['profile-due-boundary@example.test'], $this->dueEmails());
    }

    public function testDueWithoutAnyRunYet(): void
    {
        $this->scheduledOwner('profile-due-first@example.test', 168);

        self::assertSame(['profile-due-first@example.test'], $this->dueEmails());
    }

    public function testNotDueWhileARunIsActive(): void
    {
        $owner = $this->scheduledOwner('profile-due-active@example.test', 6);
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-02 00:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        self::assertSame([], $this->dueEmails());
    }

    public function testSkippedWithoutAConnectionThatCanBuildTheProfile(): void
    {
        $owner = $this->user('profile-due-jev@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $this->schedule($owner, 6);

        self::assertSame([], $this->dueEmails());
    }

    public function testAManualScheduleIsNeverDue(): void
    {
        $owner = $this->user('profile-due-manual@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $this->writer()->saveProfileSettings($owner, new ProfileSettingsValues(null, null, 40, 80));

        self::assertSame([], $this->dueEmails());
    }

    private function scheduledOwner(string $email, int $hours): User
    {
        $owner = $this->user($email);
        $this->fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $this->schedule($owner, $hours);

        return $owner;
    }

    private function schedule(User $owner, int $hours): void
    {
        $this->writer()->saveProfileSettings($owner, new ProfileSettingsValues($hours, null, 40, 80));
    }

    private function failedRunAt(User $owner, string $createdAt): void
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Scheduled, new \DateTimeImmutable($createdAt));
        $profileRun->fail('irrelevant', new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
    }

    private function completedRunAt(User $owner, string $createdAt): void
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Scheduled, new \DateTimeImmutable($createdAt));
        $profileRun->start('fingerprint', 'api.example.test', 'qwen3-14b');
        $profileRun->complete(ProfileRunOutcome::Unchanged, new \DateTimeImmutable($createdAt));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function dueEmails(): array
    {
        $container = self::getContainer();
        /** @var RecommendationSettingsRepository $settings */
        $settings = $container->get(RecommendationSettingsRepository::class);
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $container->get(ProfileRunRepository::class);
        /** @var ProfileConnections $connections */
        $connections = $container->get(ProfileConnections::class);
        $finder = new DueProfileRunFinder($settings, $profileRuns, $connections, new MockClock(self::NOW));

        return array_map(static fn (User $user): string => $user->getEmail(), $finder->due());
    }

    private function writer(): RecommendationSettingsWriter
    {
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);

        return $writer;
    }
}
