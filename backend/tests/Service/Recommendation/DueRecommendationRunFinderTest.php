<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\DueRecommendationRunFinder;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DueRecommendationRunFinderTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function setCadence(User $user, int $hours): void
    {
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $writer->save($user, new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            autoGenerateIntervalHours: $hours,
        ));
    }

    /** A terminal (non-active) run with a chosen start time, so the anchor is testable. fail() is reachable from PENDING. */
    private function pastFailedRun(User $user, string $ago): void
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable($ago));
        $run->fail('irrelevant', new \DateTimeImmutable($ago));
        $this->em->persist($run);
        $this->em->flush();
    }

    /** @return list<string> */
    private function dueEmails(): array
    {
        $finder = self::getContainer()->get(DueRecommendationRunFinder::class);
        self::assertInstanceOf(DueRecommendationRunFinder::class, $finder);

        return array_map(static fn (User $user): string => $user->getEmail(), $finder->due());
    }

    public function testDueWhenTheAnchorElapsed(): void
    {
        $user = $this->user('finder-due@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 3);
        $this->pastFailedRun($user, '-5 hours');
        $this->em->clear();

        self::assertContains('finder-due@example.test', $this->dueEmails());
    }

    public function testNotDueInsideTheInterval(): void
    {
        $user = $this->user('finder-fresh@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 6);
        $this->pastFailedRun($user, '-1 hour');
        $this->em->clear();

        self::assertNotContains('finder-fresh@example.test', $this->dueEmails());
    }

    public function testNotDueWhileARunIsActive(): void
    {
        $user = $this->user('finder-active@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 1);
        $starter = self::getContainer()->get(RecommendationRunStarter::class);
        self::assertInstanceOf(RecommendationRunStarter::class, $starter);
        $starter->start($user); // a PENDING (active) run
        $this->em->clear();

        self::assertNotContains('finder-active@example.test', $this->dueEmails());
    }

    public function testSkippedWhenAiIsNotReady(): void
    {
        $user = $this->user('finder-no-ai@example.test');
        $this->setCadence($user, 1); // deliberately no seedReadyAiSettings
        $this->em->clear();

        self::assertNotContains('finder-no-ai@example.test', $this->dueEmails());
    }

    public function testDueWhenNoPriorRunExists(): void
    {
        $user = $this->user('finder-never-ran@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 24);
        $this->em->clear();

        self::assertContains('finder-never-ran@example.test', $this->dueEmails());
    }
}
