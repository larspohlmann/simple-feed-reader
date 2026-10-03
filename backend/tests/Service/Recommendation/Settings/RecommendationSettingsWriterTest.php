<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Settings;

use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Enum\RecommendationBatchSize;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationSettingsWriterTest extends DbTestCase
{
    private User $user;
    private RecommendationSettingsWriter $writer;
    private RecommendationSettingsRepository $recommendationSettings;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create(
            'recommendation-settings-writer@example.test',
        );

        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $this->writer = $writer;

        /** @var RecommendationSettingsRepository $repository */
        $repository = self::getContainer()->get(RecommendationSettingsRepository::class);
        $this->recommendationSettings = $repository;
    }

    public function testStoreProfilePersistsTheWholeProfile(): void
    {
        $this->writer->storeProfile($this->user, $this->typographyProfile());

        $stored = $this->reloaded()->getStoredProfile();
        self::assertSame('Likes long-form essays on typography.', $stored->getText());
        self::assertSame('2026-10-03 07:15:00', $stored->getGeneratedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('llm.example.test', $stored->getProviderHost());
        self::assertSame('qwen3-14b', $stored->getModel());
    }

    public function testStoreProfileCreatesARowWhenNoneExists(): void
    {
        $this->writer->storeProfile($this->userWithoutSettingsRow(), $this->typographyProfile());

        self::assertNotNull($this->recommendationSettings->findForUser($this->userWithoutSettingsRow()));
    }

    public function testStoreProfileLeavesTheSettingsAndTheProfileSettingsUntouched(): void
    {
        $this->writer->save($this->user, $this->values(showScoreAndReasons: false));
        $this->writer->saveProfileSettings($this->user, new ProfileSettingsValues(48, null, 20, 30));

        $this->writer->storeProfile($this->user, $this->typographyProfile());

        $reloaded = $this->reloaded();
        self::assertSame('Only cats.', $reloaded->values()->guidancePrompt);
        self::assertSame(10, $reloaded->values()->favoritesCap);
        self::assertSame(65536, $reloaded->values()->contextWindow);
        self::assertTrue($reloaded->values()->debugEnabled);
        self::assertSame(48, $reloaded->profileSettings()->intervalHours);
        self::assertSame(20, $reloaded->profileSettings()->keptCap);
        self::assertSame(30, $reloaded->profileSettings()->viewedCap);
    }

    public function testSavingSettingsPersistsShowScoreAndReasons(): void
    {
        $this->writer->save($this->user, $this->values(showScoreAndReasons: true));

        self::assertTrue($this->reloaded()->values()->showScoreAndReasons);
    }

    public function testSavingSettingsKeepsTheStoredProfileAndTheProfileSettings(): void
    {
        $this->writer->storeProfile($this->user, $this->typographyProfile());
        $this->writer->saveProfileSettings($this->user, new ProfileSettingsValues(168, null, 20, 30));

        $this->writer->save($this->user, $this->values(showScoreAndReasons: false));

        $reloaded = $this->reloaded();
        self::assertSame('Likes long-form essays on typography.', $reloaded->getStoredProfile()->getText());
        self::assertSame(168, $reloaded->profileSettings()->intervalHours);
        self::assertSame(20, $reloaded->profileSettings()->keptCap);
        self::assertSame(30, $reloaded->profileSettings()->viewedCap);
    }

    public function testSaveProfileSettingsPersistsTheScheduleAndTheCaps(): void
    {
        $this->writer->saveProfileSettings($this->user, new ProfileSettingsValues(6, null, 7, 9));

        $profileSettings = $this->reloaded()->profileSettings();
        self::assertSame(6, $profileSettings->intervalHours);
        self::assertSame(7, $profileSettings->keptCap);
        self::assertSame(9, $profileSettings->viewedCap);
    }

    private function values(bool $showScoreAndReasons): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            favoritesCap: 10,
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: 65536,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: true,
            showScoreAndReasons: $showScoreAndReasons,
        );
    }

    private function typographyProfile(): StoredProfile
    {
        return new StoredProfile(
            'Likes long-form essays on typography.',
            new \DateTimeImmutable('2026-10-03 07:15:00'),
            'llm.example.test',
            'qwen3-14b',
        );
    }

    private function reloaded(): RecommendationSettings
    {
        $this->entityManager->clear();
        $reloaded = $this->recommendationSettings->findForUser($this->user);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function userWithoutSettingsRow(): User
    {
        return $this->user;
    }
}
