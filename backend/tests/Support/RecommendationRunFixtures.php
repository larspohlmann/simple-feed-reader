<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\AiProviderSettings;
use App\Entity\CallOutcome;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationHistoryCaps;
use App\Entity\RecommendationPoolLimits;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\RecommendationSettingsValues;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\RecommendationBatchSize;
use App\Repository\CallSettlement;
use App\Repository\RecommendationCallRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The recommendation pipeline's shared fixtures (a ready AI connection, candidate entries, a run and its log rows), so
 * the tests that drive real runs all build them one way.
 */
final readonly class RecommendationRunFixtures
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ApiKeyCipher $cipher,
    ) {
    }

    public function seedReadyAiSettings(User $user): void
    {
        $userId = $user->requireId();
        $sealed = $this->cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, null, 'https://api.example.test/v1', $sealed, '1234', $now);
        $this->entityManager->persist($settings);
        $settings->chooseModel('m', $now, 32768);
        $user->setActiveAiProviderSettings($settings);
        $this->entityManager->flush();
    }

    /**
     * Writes the connection's per-batch ceiling, the knob the resolver reads, so a test forces an exact batch count.
     * Not flushed, like createRun(). Requires seedReadyAiSettings() first.
     */
    public function capBatchesAt(User $user, int $maximumBatchSize): void
    {
        $provider = $user->getActiveAiProviderSettings()
            ?? throw new \LogicException('Cannot cap batches before a provider is seeded.');
        $provider->setMaxBatchSize($maximumBatchSize);
    }

    /** The smallest account that can run: a ready AI connection and five candidates, which fit in one batch. */
    public function seedSingleBatchFixture(User $user): void
    {
        $this->seedReadyAiSettings($user);
        $this->seedFeedWithEntries($user, 5);
    }

    /**
     * A subscribed feed carrying $entryCount candidate entries, the most
     * recent first. Returned so a caller that needs to enrich the entries
     * (a summary, a stamp) can reach them.
     *
     * @return list<Entry>
     */
    public function seedFeedWithEntries(User $user, int $entryCount): array
    {
        $feed = $this->subscribedFeed($user);
        $entries = [];

        for ($index = 0; $index < $entryCount; $index++) {
            // One distinct minute per entry, all well inside the look-back
            // window, and never zero minutes ago.
            $entries[] = $this->entry($feed, $user->getEmail() . '-entry-' . $index, $entryCount - $index);
        }

        return $entries;
    }

    public function subscribedFeed(User $user): Feed
    {
        $feed = new Feed('https://example.com/' . $user->getEmail() . '/feed.xml');
        $feed->setTitle('Example');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager->flush();

        return $feed;
    }

    /**
     * The account loses its AI configuration mid-run: the row is deleted and the identity map cleared. The pointer is
     * also nulled on $user itself, since ON DELETE SET NULL reaches the column, not an instance a caller still holds.
     */
    public function deleteAiSettings(User $user): void
    {
        $settings = $this->entityManager->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user])
            ?? throw new \LogicException('Expected AI settings to exist for this user.');
        $this->entityManager->remove($settings);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $user->setActiveAiProviderSettings(null);
    }

    /** Not flushed: callers batch several rows before the one flush that makes them all visible together. */
    public function createRun(User $user): RecommendationRun
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->entityManager->persist($run);

        return $run;
    }

    /** A run created at $createdAt, and flushed, for tests that assert on timing; createRun() fixes its own date. */
    public function persistRunAt(User $user, \DateTimeImmutable $createdAt): RecommendationRun
    {
        $run = new RecommendationRun($user, $createdAt);
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /**
     * Writes the price with raw SQL, as RecordedCall banks it in production; the entity has no setter. The identity map
     * is cleared, so a caller re-fetches $run and its User before using them as associations.
     */
    public function priceRun(RecommendationRun $run, int $costNanoCredits): void
    {
        $id = $run->requireId();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE recommendation_run SET cost_nano_credits = :cost WHERE id = :id',
            ['cost' => $costNanoCredits, 'id' => $id],
        );
        $this->entityManager->clear();
    }

    /** Not flushed, like createRun(). $createdAt defaults to the fixed instant createRun() gives every run. */
    public function log(
        RecommendationRun $run,
        CallPhase $phase,
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
        $this->entityManager->persist($log);

        return $log;
    }

    /** Settles the row the way RecordedCall does, through the DBAL writer, then re-reads the managed entity. */
    public function settleLog(RecommendationRunLog $log, string $responseText, CallOutcome $outcome): void
    {
        $this->entityManager->flush();
        (new RecommendationCallRepository($this->entityManager->getConnection()))->settleAnswered(
            new CallSettlement($log->requireId(), $outcome),
            $responseText,
        );
        $this->entityManager->refresh($log);
    }

    /** Default settings with debug on, which drives only the per-run call log and nothing in the feed payload. */
    public function debugEnabledSettings(User $user): RecommendationSettings
    {
        return $this->recommendationSettings($user, true);
    }

    public function debugDisabledSettings(User $user): RecommendationSettings
    {
        return $this->recommendationSettings($user, false);
    }

    /** Reasons on, debug off: proves the reason and its score ride on the reader's own preference alone. */
    public function showReasonsEnabledSettings(User $user): RecommendationSettings
    {
        return $this->recommendationSettings($user, false, showReasons: true);
    }

    /** Both on: the flags are independent, so the pair is exercised to prove debug takes nothing from showReasons. */
    public function showReasonsAndDebugEnabledSettings(User $user): RecommendationSettings
    {
        return $this->recommendationSettings($user, true, showReasons: true);
    }

    private function recommendationSettings(
        User $user,
        bool $debugEnabled,
        bool $showReasons = false,
    ): RecommendationSettings {
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            historyCaps: RecommendationHistoryCaps::defaults(),
            poolLimits: RecommendationPoolLimits::defaults(),
            contextWindow: null,
            batchSize: RecommendationBatchSize::Medium,
            debugEnabled: $debugEnabled,
            showReasons: $showReasons,
        ));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return $settings;
    }

    /** Stamped $minutesAgo before now: an absolute date would age out of the pool's look-back window. */
    public function entry(Feed $feed, string $guid, int $minutesAgo): Entry
    {
        $effectiveDate = new \DateTimeImmutable(\sprintf('-%d minutes', $minutesAgo));
        $entry = new Entry(
            $feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('-1 year'),
            $effectiveDate,
        );
        $entry->setPublishedAt($effectiveDate);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}
