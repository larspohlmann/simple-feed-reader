<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Settings;

use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationSettingsValues;
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

    public function testStoreProfilePersistsOnlyTheProfileText(): void
    {
        $this->writer->storeProfile($this->user, 'Likes long-form essays on typography.');

        $reloaded = $this->recommendationSettings->findForUser($this->user);
        self::assertNotNull($reloaded);
        self::assertSame('Likes long-form essays on typography.', $reloaded->values()->profileText);
    }

    public function testStoreProfileCreatesARowWhenNoneExists(): void
    {
        $this->writer->storeProfile($this->userWithoutSettingsRow(), 'Likes maps and cartography.');

        self::assertNotNull($this->recommendationSettings->findForUser($this->userWithoutSettingsRow()));
    }

    /**
     * The one field storeProfile() may change is the profile text itself;
     * everything else on the row must survive it untouched.
     */
    public function testStoreProfileLeavesOtherFieldsUntouched(): void
    {
        $this->writer->save($this->user, new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            historyCaps: $this->nonDefaultHistoryCaps(),
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: 65536,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: true,
        ));

        $this->writer->storeProfile($this->user, 'Likes long-form essays on typography.');

        $reloaded = $this->recommendationSettings->findForUser($this->user);
        self::assertNotNull($reloaded);
        $values = $reloaded->values();
        self::assertSame('Only cats.', $values->guidancePrompt);
        self::assertSame(10, $values->historyCaps->favorites);
        self::assertSame(20, $values->historyCaps->kept);
        self::assertSame(30, $values->historyCaps->viewed);
        self::assertSame(65536, $values->contextWindow);
        self::assertSame(RecommendationBatchSize::Large, $values->batchSize);
        self::assertTrue($values->debugEnabled);
        self::assertSame('Likes long-form essays on typography.', $values->profileText);
    }

    /**
     * save() rebuilds the values twice, to normalise the guidance and to re-attach the stored profile: both rebuilds
     * must carry showReasons, or the toggle silently resets to its default.
     */
    public function testSavingSettingsPersistsShowReasons(): void
    {
        $this->writer->save($this->user, new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            historyCaps: $this->nonDefaultHistoryCaps(),
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: 65536,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: false,
            showReasons: true,
        ));

        $reloaded = $this->recommendationSettings->findForUser($this->user);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->values()->showReasons);
    }

    /**
     * The settings form never carries profileText, so save() always receives a null one: it must keep whatever
     * storeProfile() already wrote.
     */
    public function testSavingSettingsDoesNotWipeAnExistingProfile(): void
    {
        $this->writer->storeProfile($this->user, 'Likes long-form essays on typography.');

        $this->writer->save($this->user, new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            historyCaps: $this->nonDefaultHistoryCaps(),
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: 65536,
            batchSize: RecommendationBatchSize::Large,
            debugEnabled: true,
        ));

        $reloaded = $this->recommendationSettings->findForUser($this->user);
        self::assertNotNull($reloaded);
        self::assertSame('Likes long-form essays on typography.', $reloaded->values()->profileText);
        self::assertSame('Only cats.', $reloaded->values()->guidancePrompt);
    }

    private function nonDefaultHistoryCaps(): RecommendationHistoryCaps
    {
        return new RecommendationHistoryCaps(10, 20, 30);
    }

    private function userWithoutSettingsRow(): User
    {
        return $this->user;
    }
}
