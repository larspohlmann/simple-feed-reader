<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repository and entity manager, not mocks: storeProfile()'s
 * job is precisely the load-or-create branch and the "touch only this field"
 * guarantee, both of which a mock would have to encode itself instead of
 * proving.
 */
final class RecommendationSettingsWriterTest extends DbTestCase
{
    private User $user;
    private RecommendationSettingsWriter $writer;
    private RecommendationSettingsRepository $settingsRepository;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('recommendation-settings-writer@example.test');

        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        $this->writer = $writer;

        /** @var RecommendationSettingsRepository $repository */
        $repository = self::getContainer()->get(RecommendationSettingsRepository::class);
        $this->settingsRepository = $repository;
    }

    public function testStoreProfilePersistsOnlyTheProfileText(): void
    {
        $this->writer->storeProfile($this->user, 'Likes long-form essays on typography.');

        $reloaded = $this->settingsRepository->findForUser($this->user);
        self::assertNotNull($reloaded);
        self::assertSame('Likes long-form essays on typography.', $reloaded->values()->profileText);
    }

    public function testStoreProfileCreatesARowWhenNoneExists(): void
    {
        $this->writer->storeProfile($this->userWithoutSettingsRow(), 'Likes maps and cartography.');

        self::assertNotNull($this->settingsRepository->findForUser($this->userWithoutSettingsRow()));
    }

    /**
     * The one field storeProfile() may change is the profile text itself;
     * everything else on the row must survive it untouched.
     */
    public function testStoreProfileLeavesOtherFieldsUntouched(): void
    {
        $this->writer->save($this->user, new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            favoritesCap: 10,
            keptCap: 20,
            viewedCap: 30,
            candidatePoolSize: 500,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: 50,
            contextWindow: 65536,
            batchCount: 12,
            debugEnabled: true,
        ));

        $this->writer->storeProfile($this->user, 'Likes long-form essays on typography.');

        $reloaded = $this->settingsRepository->findForUser($this->user);
        self::assertNotNull($reloaded);
        $values = $reloaded->values();
        self::assertSame('Only cats.', $values->guidancePrompt);
        self::assertSame(10, $values->favoritesCap);
        self::assertSame(20, $values->keptCap);
        self::assertSame(30, $values->viewedCap);
        self::assertSame(65536, $values->contextWindow);
        self::assertSame(12, $values->batchCount);
        self::assertTrue($values->debugEnabled);
        self::assertSame('Likes long-form essays on typography.', $values->profileText);
    }

    /**
     * The regression case: save() is the settings-form path, and the form
     * never carries profileText (it is read-only there), so
     * SaveRecommendationSettingsRequest::values() always hands save() a
     * RecommendationSettingsValues with profileText null. save() must not
     * take that null at face value -- it has to preserve whatever
     * storeProfile() already wrote.
     */
    public function testSavingSettingsDoesNotWipeAnExistingProfile(): void
    {
        $this->writer->storeProfile($this->user, 'Likes long-form essays on typography.');

        $this->writer->save($this->user, new RecommendationSettingsValues(
            guidancePrompt: 'Only cats.',
            favoritesCap: 10,
            keptCap: 20,
            viewedCap: 30,
            candidatePoolSize: 500,
            lookbackDays: EffectiveRecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            picksLimit: 50,
            contextWindow: 65536,
            batchCount: 12,
            debugEnabled: true,
        ));

        $reloaded = $this->settingsRepository->findForUser($this->user);
        self::assertNotNull($reloaded);
        self::assertSame('Likes long-form essays on typography.', $reloaded->values()->profileText);
        self::assertSame('Only cats.', $reloaded->values()->guidancePrompt);
    }

    /**
     * A fresh user out of setUp() never had a settings row created for it;
     * this name exists to make that precondition explicit at the call site.
     */
    private function userWithoutSettingsRow(): User
    {
        return $this->user;
    }
}
