<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsValues;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The recommendation pipeline's most-seeded fixtures: a ready-to-call AI
 * connection, an unread entry, a run and its debug-log rows.
 * RecommendationRunAdvancerTest and AdvanceRecommendationRunsHandlerTest both
 * drive real runs end to end, so both need the exact same "an account that
 * can actually call a provider" and "an entry that actually shows up as a
 * candidate" setup; RecommendationDebugLogControllerTest and
 * RecommendationRunLogRepositoryTest both need the exact same run and
 * debug-log row shape — a second near-identical copy would drift the moment
 * one of them changed a default.
 */
final readonly class RecommendationRunFixtures
{
    public function __construct(
        private EntityManagerInterface $em,
        private ApiKeyCipher $cipher,
    ) {
    }

    public function seedReadyAiSettings(User $user): void
    {
        $userId = $user->getId() ?? throw new \LogicException('Cannot seed AI settings for an unsaved account.');
        $sealed = $this->cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, null, 'https://api.example.test/v1', $sealed, '1234', $now);
        $this->em->persist($settings);
        $settings->chooseModel('m', $now, 32768);
        $user->addAiProviderSettings($settings);
        $user->setActiveAiProviderSettings($settings);
        $this->em->flush();
    }

    /**
     * Simulates the account losing its AI configuration mid-run: both
     * RecommendationRunAdvancerTest and AdvanceRecommendationRunsHandlerTest
     * drive that race, and both need the row gone and the identity map clear
     * before the next tick sees it.
     *
     * The active pointer is also cleared on the in-memory $user directly:
     * ON DELETE SET NULL clears the database column, but a caller that keeps
     * driving this exact $user instance afterward (rather than reloading it)
     * would otherwise still read the now-deleted row off the object's own
     * property, which em->clear() does not touch.
     */
    public function deleteAiSettings(User $user): void
    {
        $settings = $this->em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user])
            ?? throw new \LogicException('Expected AI settings to exist for this user.');
        $this->em->remove($settings);
        $this->em->flush();
        $this->em->clear();
        $user->setActiveAiProviderSettings(null);
    }

    /** Not flushed: callers batch several rows (often a `finish()` on top of
     *  each) before the one flush that makes them all visible together. */
    public function createRun(User $user): RecommendationRun
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->em->persist($run);

        return $run;
    }

    /**
     * Not flushed, for the same reason as {@see createRun()}. $createdAt
     * defaults to the run's own creation instant — most callers don't care
     * about call timing and only a handful of #321 tests need to pin it.
     */
    public function log(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
        ?\DateTimeImmutable $createdAt = null,
    ): RecommendationRunLog {
        $log = new RecommendationRunLog(
            $run,
            $phase,
            $batchNumber,
            $attempt,
            $requestBody,
            $createdAt ?? new \DateTimeImmutable('2026-08-08T10:00:00Z'),
        );
        $this->em->persist($log);

        return $log;
    }

    /**
     * The default-valued settings row three tests need only to flip the
     * debug switch on: EntryControllerTest, ForYouFeedResponderTest and
     * RecommendationCallRecorderTest each assert on debug-only behaviour
     * (the score column, the debug-log rows) and don't care about any other
     * field. Two named methods instead of a boolean flag, so a call site
     * reads as what it means rather than what it passes.
     */
    public function debugEnabledSettings(User $user): RecommendationSettings
    {
        return $this->recommendationSettings($user, true);
    }

    public function debugDisabledSettings(User $user): RecommendationSettings
    {
        return $this->recommendationSettings($user, false);
    }

    private function recommendationSettings(User $user, bool $debugEnabled): RecommendationSettings
    {
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: $debugEnabled,
        ));
        $this->em->persist($settings);
        $this->em->flush();

        return $settings;
    }

    public function entry(Feed $feed, string $guid, string $published): Entry
    {
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setPublishedAt(new \DateTimeImmutable($published));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
