<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Service\Worker\Handler\StartDueRecommendationRunsHandler;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class StartDueRecommendationRunsHandlerTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    private function aiReadyUserWithCadence(string $email, ?int $hours): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($this->em, $hasher))->create($email);
        $this->fixtures->seedReadyAiSettings($user);

        if (null !== $hours) {
            $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
            self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
            $writer->save($user, new RecommendationSettingsValues(
                guidancePrompt: null,
                favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
                keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
                viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
                candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
                lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
                picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
                contextWindow: null,
                batchCount: null,
                debugEnabled: false,
                autoGenerateIntervalHours: $hours,
            ));
        }

        return $user;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function handler(): StartDueRecommendationRunsHandler
    {
        $handler = self::getContainer()->get(StartDueRecommendationRunsHandler::class);
        self::assertInstanceOf(StartDueRecommendationRunsHandler::class, $handler);

        return $handler;
    }

    public function testItStartsARunForADueOptedInUser(): void
    {
        $user = $this->aiReadyUserWithCadence('start-due-opted-in@example.test', 1);

        $this->handler()->__invoke(new StartDueRecommendationRuns());
        $this->em->clear();

        self::assertNotNull($this->runs()->findActiveForUser($user));
    }

    public function testItStartsNothingForAUserWithoutACadence(): void
    {
        $user = $this->aiReadyUserWithCadence('start-due-no-cadence@example.test', null);

        $this->handler()->__invoke(new StartDueRecommendationRuns());
        $this->em->clear();

        self::assertNull($this->runs()->findActiveForUser($user));
    }
}
