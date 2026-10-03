<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\RecommendationSettings;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Enum\CallVerdict;
use App\Enum\ProfileRunOutcome;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Exception\RetryableProviderException;
use App\Service\Ai\Model\RetryPlanModel;
use App\Service\Ai\RateLimitedCalls;
use App\Service\Recommendation\Llm\Prompt\Support\RecommendationPromptText;
use App\Service\Recommendation\Profile\ProfileConnections;
use App\Service\Recommendation\Profile\ProfileRunTick;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Tests\DbTestCase;
use App\Tests\Support\AiSettingsRowMover;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use Symfony\Component\Clock\MockClock;

final class ProfileRunTickTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-run-tick@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
    }

    public function testAManualRunCallsTheModelOnceAndStoresTheProfile(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"Likes maps and cartography."}');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Completed, $profileRun->getStatus());
        self::assertSame(ProfileRunOutcome::Generated, $profileRun->getOutcome());
        self::assertCount(1, $this->chat()->calls());
        $stored = $this->storedProfile();
        self::assertSame('Likes maps and cartography.', $stored->getText());
        self::assertSame('qwen3-14b', $stored->getModel());
        self::assertSame('api.example.test', $stored->getProviderHost());
        self::assertNotNull($stored->getGeneratedAt());
    }

    public function testTheChosenProfileConnectionIsTheOneCalled(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->chooseProfileConnection(
            $this->owner,
            $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'profile-llm'),
        );
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);

        self::assertSame('profile-llm', $this->chat()->calls()[0]['model']);
        self::assertSame('profile-llm', $this->storedProfile()->getModel());
    }

    public function testWithoutHistoryTheRunCompletesWithoutACallAndStoresNothing(): void
    {
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::NoHistory, $profileRun->getOutcome());
        self::assertSame([], $this->chat()->calls());
        self::assertNull($this->storedProfile()->getText());
    }

    public function testAScheduledRunWithUnchangedInputsSkipsTheCall(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $scheduled = $this->profileRun(ProfileRunTrigger::Scheduled);

        $this->tick()->advance($scheduled, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Unchanged, $scheduled->getOutcome());
        self::assertCount(1, $this->chat()->calls());
        self::assertSame('First.', $this->storedProfile()->getText());
    }

    public function testAManualRunWithUnchangedInputsStillCallsTheModel(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $this->chat()->queueContent('{"profile":"Second."}');
        $manual = $this->profileRun(ProfileRunTrigger::Manual);

        $this->tick()->advance($manual, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $manual->getOutcome());
        self::assertSame('Second.', $this->storedProfile()->getText());
    }

    public function testAScheduledRunAfterTheHistoryGrewCallsTheModel(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $this->fixtures->seedFavorites($this->owner, 'rail', 1);
        $this->chat()->queueContent('{"profile":"Maps and trains."}');
        $scheduled = $this->profileRun(ProfileRunTrigger::Scheduled);

        $this->tick()->advance($scheduled, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $scheduled->getOutcome());
        self::assertSame('Maps and trains.', $this->storedProfile()->getText());
    }

    public function testAScheduledRunWithoutAStoredProfileCallsTheModelEvenWhenUnchanged(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 2);
        $this->chat()->queueContent('{"profile":"First."}');
        $this->tick()->advance($this->profileRun(ProfileRunTrigger::Manual), TickDriver::Poll);
        $this->clearStoredProfile();
        $this->chat()->queueContent('{"profile":"Again."}');
        $scheduled = $this->profileRun(ProfileRunTrigger::Scheduled);

        $this->tick()->advance($scheduled, TickDriver::Poll);

        self::assertSame(ProfileRunOutcome::Generated, $scheduled->getOutcome());
    }

    public function testAnUnusableReplyIsRetriedWithTheCorrectionAndTheThirdFailsTheRunKeepingTheProfile(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        foreach (['not json', 'still not json', '{"profil":"typo"}'] as $reply) {
            $this->chat()->queueContent($reply);
        }

        $this->tick()->advance($profileRun, TickDriver::Poll);
        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getAttempts());

        $this->tick()->advance($profileRun, TickDriver::Poll);
        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame('The model gave no usable profile in 3 attempts.', $profileRun->getError());
        self::assertSame('Earlier profile.', $this->storedProfile()->getText());
        $secondCall = $this->chat()->calls()[1]['messages'];
        self::assertSame(RecommendationPromptText::DISTILL_CORRECTIVE, $secondCall[\count($secondCall) - 1]['content']);
    }

    public function testATransportFailureIsAStrikeAndTheThirdFailsTheRunKeepingTheProfile(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        for ($strike = 0; $strike < 3; $strike++) {
            $this->chat()->queueFailure(new ProviderUnreachableException('gone'));
        }

        $this->tick()->advance($profileRun, TickDriver::Poll);
        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getTransportFailures());

        $this->tick()->advance($profileRun, TickDriver::Poll);
        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame('The AI provider at https://api.example.test/v1 failed: gone', $profileRun->getError());
        self::assertSame('Earlier profile.', $this->storedProfile()->getText());
    }

    public function testADeferringRateLimitIsAStrikeAndTheThirdFailsTheRun(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        for ($strike = 0; $strike < 3; $strike++) {
            $this->chat()->queueFailure(new RetryableProviderException(429, 20));
        }

        $this->tick()->advance($profileRun, TickDriver::Poll);
        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getTransportFailures());

        $this->tick()->advance($profileRun, TickDriver::Poll);
        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame(
            'The AI provider at https://api.example.test/v1 failed: Provider rate limited; deferring for 20 s.',
            $profileRun->getError(),
        );
    }

    public function testAnUnreadableKeyFailsTheRunAtOnceKeepingTheProfile(): void
    {
        $donor = $this->user('profile-run-tick-donor@example.test');
        $this->fixtures->seedReadyAiSettingsFor($donor, 'qwen3-14b');
        $stranger = $this->user('profile-run-tick-stranger@example.test');
        $strangerId = $stranger->requireId();
        // The donor's key is sealed under the donor's id, so it fails its integrity check on the stranger's account.
        $moved = (new AiSettingsRowMover($this->entityManager))->moveOwnership($donor, $stranger);
        $stranger = $this->entityManager->find(User::class, $strangerId);
        self::assertInstanceOf(User::class, $stranger);
        $this->fixtures->chooseProfileConnection($stranger, $moved);
        $this->fixtures->seedFavorites($stranger, 'maps', 1);
        $this->fixtures->storeProfile($stranger, 'Earlier profile.');
        $profileRun = new ProfileRun(
            $stranger,
            ProfileRunTrigger::Manual,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame(ProfileRunTick::KEY_UNREADABLE, $profileRun->getError());
        self::assertSame(0, $profileRun->getTransportFailures());
        self::assertSame([], $this->chat()->calls());
        $row = $this->entityManager->getRepository(RecommendationSettings::class)->findOneBy(['user' => $stranger]);
        self::assertSame('Earlier profile.', $row?->getStoredProfile()->getText());
    }

    public function testWithoutAUsableConnectionTheRunFailsWithoutACall(): void
    {
        $owner = $this->user('profile-run-tick-jev@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $this->fixtures->seedFavorites($owner, 'maps', 1);
        $profileRun = new ProfileRun(
            $owner,
            ProfileRunTrigger::Recommendation,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame(ProfileConnections::MISSING, $profileRun->getError());
        self::assertSame([], $this->chat()->calls());
    }

    public function testEachCallsVerdictIsSettledInTheRunLog(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueContent('not json');
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $this->tick()->advance($profileRun, TickDriver::Poll);
        $this->tick()->advance($profileRun, TickDriver::Poll);

        $rows = $this->logs()->listForProfileRun($this->owner, $profileRun->requireId());
        self::assertSame([CallVerdict::Unusable, CallVerdict::Usable], array_column($rows, 'verdict'));
    }

    public function testAnUnusableReplyIsSavedBeforeTheNextTick(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueContent('not json');

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(1, $this->saved($profileRun)->getAttempts());
    }

    public function testATransportStrikeIsSavedBeforeTheNextTick(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueFailure(new ProviderUnreachableException('gone'));

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(1, $this->saved($profileRun)->getTransportFailures());
    }

    public function testRejectedCredentialsAreAStrike(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueFailure(CredentialsRejectedException::refusedKey());

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getTransportFailures());
    }

    /** A polling tick never waits out a 5xx: it defers, and the deferral is the strike. */
    public function testAServerErrorOnAPollingTickDefersAsAStrike(): void
    {
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $this->chat()->queueFailure(new RetryableProviderException(503));

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getTransportFailures());
        self::assertCount(1, $this->chat()->calls(), 'a polling tick sends once and retries nothing');
    }

    /** The worker waits out a 5xx in blocking retries; once they are spent, the 5xx itself is the strike. */
    public function testAServerErrorThatOutlastsTheWorkersRetriesIsAStrikeAndTheThirdFailsTheRun(): void
    {
        self::getContainer()->set(RateLimitedCalls::class, new RateLimitedCalls(new MockClock()));
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->profileRun(ProfileRunTrigger::Manual);
        $callsPerTick = 1 + RetryPlanModel::blocking()->maxRetries();
        for ($reply = 0; $reply < 3 * $callsPerTick; $reply++) {
            $this->chat()->queueFailure(new RetryableProviderException(503));
        }

        $this->tick()->advance($profileRun, TickDriver::Worker);
        self::assertSame(RunStatus::Running, $profileRun->getStatus());
        self::assertSame(1, $profileRun->getTransportFailures());
        self::assertCount($callsPerTick, $this->chat()->calls());

        $this->tick()->advance($profileRun, TickDriver::Worker);
        $this->tick()->advance($profileRun, TickDriver::Worker);

        self::assertSame(RunStatus::Failed, $profileRun->getStatus());
        self::assertSame(
            'The AI provider at https://api.example.test/v1 failed: That provider answered with status 503.',
            $profileRun->getError(),
        );
        self::assertSame('Earlier profile.', $this->storedProfile()->getText());
    }

    public function testARunWithoutAUsableConnectionIsSavedAsFailed(): void
    {
        $owner = $this->user('profile-run-tick-saved-failure@example.test');
        $this->fixtures->seedReadyAiSettingsFor($owner, 'jev-latest');
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        $this->tick()->advance($profileRun, TickDriver::Poll);

        self::assertSame(RunStatus::Failed, $this->saved($profileRun)->getStatus());
    }

    private function profileRun(ProfileRunTrigger $trigger): ProfileRun
    {
        $profileRun = new ProfileRun($this->owner, $trigger, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function storedProfile(): StoredProfile
    {
        $row = $this->entityManager->getRepository(RecommendationSettings::class)->findOneBy(['user' => $this->owner]);

        return $row instanceof RecommendationSettings ? $row->getStoredProfile() : StoredProfile::none();
    }

    private function clearStoredProfile(): void
    {
        $row = $this->entityManager->getRepository(RecommendationSettings::class)->findOneBy(['user' => $this->owner]);
        self::assertInstanceOf(RecommendationSettings::class, $row);
        $row->storeProfile(StoredProfile::none());
        $this->entityManager->flush();
    }

    private function saved(ProfileRun $profileRun): ProfileRun
    {
        $profileRunId = $profileRun->requireId();
        $this->entityManager->clear();
        $saved = $this->entityManager->find(ProfileRun::class, $profileRunId);
        self::assertInstanceOf(ProfileRun::class, $saved);

        return $saved;
    }

    private function logs(): RecommendationRunLogRepository
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return $logs;
    }

    private function tick(): ProfileRunTick
    {
        /** @var ProfileRunTick $tick */
        $tick = self::getContainer()->get(ProfileRunTick::class);

        return $tick;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
