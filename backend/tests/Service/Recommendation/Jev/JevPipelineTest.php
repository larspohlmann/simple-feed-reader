<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\RecommendationEngineKind;
use App\Http\RecommendationFeedJson;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Feed\ForYouFeed;
use App\Service\Recommendation\Jev\JevProfileStep;
use App\Service\Recommendation\Jev\Support\QuestionId;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\StubSystemOneClient;

/**
 * A Jev run end to end through the advancer's real dispatch, only System One and the profile connection's chat faked:
 * snapshot, the distillation, one wave, the finalising tick. Five candidates fit one request.
 */
final class JevPipelineTest extends DbTestCase
{
    use SeedsUsers;

    private const int MAX_TICKS = 10;

    private User $owner;
    private RecommendationRunFixtures $fixtures;
    private AiProviderSettings $profileConnection;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('jev-pipeline@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
        $this->profileConnection = $this->fixtures->seedProfileConnectionFor($this->owner);
    }

    public function testEveryCandidateIsScoredByItsNoulAndRankedWithoutAReason(): void
    {
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $nouls = [$ids[0] => 0.2, $ids[1] => 0.91, $ids[2] => 0.55, $ids[3] => 0.0737, $ids[4] => 0.66];
        $this->systemOne()->queueNouls(static fn (int $entryId): float => $nouls[$entryId]);
        $this->queueProfile('Likes Rust and homelab.');

        $run = $this->runToCompletion();

        $items = $this->items($run);
        self::assertSame([$ids[1], $ids[4], $ids[2], $ids[0], $ids[3]], array_map(
            static fn (RecommendationItem $item): int => $item->getEntry()->requireId(),
            $items,
        ));
        self::assertSame([910, 660, 550, 200, 74], array_map(
            static fn (RecommendationItem $item): ?int => $item->getScore(),
            $items,
        ));
        self::assertSame(['', '', '', '', ''], array_map(
            static fn (RecommendationItem $item): string => $item->getReason(),
            $items,
        ));
        self::assertSame(RecommendationEngineKind::Jev, $run->getEngineKind());
        self::assertSame(2, $run->getProgress()->batchesTotal);   // the distillation and one batch (the LLM: 3)
        self::assertSame([RecommendationRunFixtures::PROFILE_MODEL], array_column($this->chat()->calls(), 'model'));
    }

    public function testTheRequestCarriesTheAliasTheStateAndOneQuestionPerCandidate(): void
    {
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->queueProfile('Likes Rust and homelab.');

        $this->runToCompletion();

        $requests = $this->systemOne()->requests();
        self::assertCount(1, $requests);
        self::assertSame('jev-latest', $requests[0]->model);
        self::assertSame(['profile' => 'Likes Rust and homelab.'], $requests[0]->state);
        self::assertEqualsCanonicalizing(
            array_map(QuestionId::of(...), $ids),
            array_keys($requests[0]->questions),
        );
    }

    public function testTheRunLogKeepsTheCallWithItsReceiptAndTheRunItsCost(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->queueProfile('Likes Rust and homelab.');

        $run = $this->runToCompletion();

        $this->entityManager->clear();
        $logs = $this->entityManager->getRepository(RecommendationRunLog::class)
            ->findBy(['run' => $run->requireId()], ['id' => 'ASC']);
        self::assertCount(2, $logs);
        self::assertSame(CallPhase::Distill, $logs[0]->getPhase());
        $log = $logs[1];
        self::assertSame(CallPhase::Batch, $log->getPhase());
        self::assertSame(1, $log->getBatchNumber());
        self::assertSame(CallVerdict::Usable, $log->getVerdict());
        self::assertSame(StubSystemOneClient::REQUEST_ID, $log->getRequestId());
        self::assertSame(StubSystemOneClient::ANSWERING_MODEL, $log->getAnsweringModel());
        self::assertSame(StubSystemOneClient::COST_NANO_CREDITS, $log->getCostNanoCredits());
        self::assertStringContainsString('"question"', $log->getRequestBody());
        $fresh = $this->runs()->find($run->requireId());
        self::assertNotNull($fresh);
        self::assertSame(StubSystemOneClient::COST_NANO_CREDITS, $fresh->getCostNanoCredits());
        self::assertSame(StubSystemOneClient::INPUT_TOKENS, $fresh->getPromptTokens());
    }

    /** With "show score and reasons" on, a Jev pick carries its score and an empty reason. */
    public function testTheForYouPageShowsTheScoreWithoutAReason(): void
    {
        $this->fixtures->showScoreAndReasonsEnabledSettings($this->owner);
        $ids = $this->entryIds($this->fixtures->seedFeedWithEntries($this->owner, 5));
        $this->systemOne()->queueNouls(static fn (int $entryId): float => $entryId === $ids[2] ? 0.97 : 0.1);
        $this->queueProfile('Likes Rust and homelab.');
        $this->runToCompletion();

        /** @var ForYouFeed $feed */
        $feed = self::getContainer()->get(ForYouFeed::class);
        $page = RecommendationFeedJson::page($feed->page(new ForYouFeedQuery($this->owner)));

        self::assertSame($ids[2], $page['entries'][0]['id']);
        self::assertSame(970, $page['entries'][0]['recommendationScore']);
        self::assertSame('', $page['entries'][0]['recommendationReason']);
    }

    public function testTheGuidanceRidesBesideTheProfile(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->fixtures->guidanceSettings($this->owner, 'More self-hosting.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->queueProfile('Likes Rust and homelab.');

        $this->runToCompletion();

        self::assertSame(
            ['profile' => 'Likes Rust and homelab.', 'guidance' => 'More self-hosting.'],
            $this->systemOne()->requests()[0]->state,
        );
    }

    /** No profile connection: the run fails before any call, says how to fix it, and resumes once one is chosen. */
    public function testWithoutAProfileConnectionTheRunFailsThenResumesOnceOneIsChosen(): void
    {
        $this->profileConnection->setProfileSource(false);
        $this->entityManager->flush();
        $this->fixtures->seedFeedWithEntries($this->owner, 5);

        $failed = $this->runToCompletion();

        self::assertSame('failed', $failed->getStatus()->value);
        self::assertSame(JevProfileStep::NO_PROFILE_CONNECTION, $failed->getError());
        self::assertSame([], $this->chat()->calls());
        self::assertSame([], $this->systemOne()->requests());

        $this->profileConnection->setProfileSource(true);
        $this->entityManager->flush();
        $this->queueProfile('Likes Rust and homelab.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->starter()->resume($this->owner);
        $resumed = $this->tickUntilDone();

        self::assertSame('completed', $resumed->getStatus()->value);
        self::assertSame($failed->requireId(), $resumed->requireId());
    }

    public function testTheWavesCompleteWhenTheProfileConnectionIsRemovedAfterTheProfileIsRecorded(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->queueProfile('Likes Rust and homelab.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->starter()->start($this->owner);
        $this->tickUntilTheProfileIsRecorded();

        $this->entityManager->remove($this->profileConnection);
        $this->entityManager->flush();
        $run = $this->tickUntilDone();

        self::assertSame('completed', $run->getStatus()->value);
        self::assertCount(1, $this->systemOne()->requests());
    }

    /** The profile connection answers nothing usable: the run scores on the profile an earlier run stored. */
    public function testAFailedDistillationFallsBackToTheStoredProfile(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->settingsWriter()->storeProfile($this->owner, 'Stored: likes Rust.');
        $this->queueUnusableProfiles();
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);

        $run = $this->runToCompletion();

        self::assertSame('completed', $run->getStatus()->value);
        self::assertSame('Stored: likes Rust.', $run->getProfileText());
        self::assertSame(['profile' => 'Stored: likes Rust.'], $this->systemOne()->requests()[0]->state);
    }

    /** Guidance alone is not enough: with nothing stored the run fails, then a usable answer on resume completes it. */
    public function testAFailedDistillationWithNothingStoredFailsEvenWithGuidanceThenResumes(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->fixtures->guidanceSettings($this->owner, 'More self-hosting.');
        $this->queueUnusableProfiles();

        $failed = $this->runToCompletion();

        self::assertSame('failed', $failed->getStatus()->value);
        self::assertSame(JevProfileStep::NO_PROFILE, $failed->getError());
        self::assertSame([], $this->systemOne()->requests());

        $this->queueProfile('Likes Rust and homelab.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->starter()->resume($this->owner);
        $resumed = $this->tickUntilDone();

        self::assertSame('completed', $resumed->getStatus()->value);
        self::assertSame(
            ['profile' => 'Likes Rust and homelab.', 'guidance' => 'More self-hosting.'],
            $this->systemOne()->requests()[0]->state,
        );
    }

    /** A resume starts the distillation's attempts afresh: one more unusable reply is retried, not the end. */
    public function testAResumedRunGetsEveryDistillationAttemptAgain(): void
    {
        $this->fixtures->seedFeedWithEntries($this->owner, 5);
        $this->queueUnusableProfiles();
        $failed = $this->runToCompletion();
        self::assertSame(JevProfileStep::NO_PROFILE, $failed->getError());

        $this->chat()->queueContent('not a profile');
        $this->queueProfile('Likes Rust and homelab.');
        $this->systemOne()->queueNouls(static fn (int $entryId): float => 0.5);
        $this->starter()->resume($this->owner);
        $resumed = $this->tickUntilDone();

        self::assertSame('completed', $resumed->getStatus()->value);
        self::assertSame('Likes Rust and homelab.', $resumed->getProfileText());
    }

    private function queueProfile(string $profile): void
    {
        $this->chat()->queueContent(json_encode(['profile' => $profile], \JSON_THROW_ON_ERROR));
    }

    private function queueUnusableProfiles(): void
    {
        for ($attempt = 0; $attempt < RecommendationRun::MAX_ATTEMPTS; $attempt++) {
            $this->chat()->queueContent('not a profile');
        }
    }

    private function settingsWriter(): RecommendationSettingsWriter
    {
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);

        return $writer;
    }

    private function runToCompletion(): RecommendationRun
    {
        $this->starter()->start($this->owner);

        return $this->tickUntilDone();
    }

    private function tickUntilDone(): RecommendationRun
    {
        for ($tick = 0; $tick < self::MAX_TICKS; $tick++) {
            if (null === $this->runs()->findActiveForUser($this->owner)) {
                break;
            }
            $this->advancer()->advance($this->owner);
        }
        self::assertNull($this->runs()->findActiveForUser($this->owner), 'The run did not end within the tick budget.');

        $run = $this->runs()->findLatestForUser($this->owner);
        self::assertNotNull($run);

        return $run;
    }

    private function tickUntilTheProfileIsRecorded(): void
    {
        for ($tick = 0; $tick < self::MAX_TICKS; $tick++) {
            if (null !== $this->runs()->findActiveForUser($this->owner)?->getProfileText()) {
                return;
            }
            $this->advancer()->advance($this->owner);
        }
        self::fail('The profile was not recorded within the tick budget.');
    }

    /** @return list<RecommendationItem> */
    private function items(RecommendationRun $run): array
    {
        $this->entityManager->clear();

        /** @var list<RecommendationItem> $items */
        $items = $this->entityManager->getRepository(RecommendationItem::class)
            ->findBy(['run' => $run->requireId()], ['position' => 'ASC']);

        return $items;
    }

    /**
     * @param list<Entry> $entries
     *
     * @return list<int>
     */
    private function entryIds(array $entries): array
    {
        return array_map(static fn (Entry $entry): int => $entry->requireId(), $entries);
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $runs */
        $runs = $this->entityManager->getRepository(RecommendationRun::class);

        return $runs;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    private function systemOne(): StubSystemOneClient
    {
        /** @var StubSystemOneClient $client */
        $client = self::getContainer()->get(StubSystemOneClient::class);

        return $client;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }
}
