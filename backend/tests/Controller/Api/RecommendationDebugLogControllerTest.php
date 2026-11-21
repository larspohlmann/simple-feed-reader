<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CallOutcome;
use App\Entity\ProfileRun;
use App\Entity\RecommendationRunLog;
use App\Entity\StoryAiRun;
use App\Entity\User;
use App\Enum\CallPhase;
use App\Enum\CallVerdict;
use App\Enum\ProfileRunTrigger;
use App\Enum\RecommendationEngineKind;
use App\Enum\RunStatus;
use App\Enum\StoryAiTask;
use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use Symfony\Component\HttpFoundation\Response;

final class RecommendationDebugLogControllerTest extends ApiTestCase
{
    private const string ROUTE = '/api/recommendations/runs/debug-log';

    private function fixtures(): RecommendationRunFixtures
    {
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        return new RecommendationRunFixtures($this->entityManager(), $cipher);
    }

    public function testListReturnsEntriesWithStreamingTextOnlyForTheOpenCall(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-list@example.test');
        $run = $this->fixtures()->createRun($user);
        $finished = $this->fixtures()->log(
            $run,
            CallPhase::Batch,
            1,
            1,
            'req a',
            new \DateTimeImmutable('2026-08-08T10:00:00Z'),
        );
        $this->fixtures()->settleLog(
            $finished,
            'done text',
            new CallOutcome(
                CallVerdict::Usable,
                1_900_000,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'stop',
            ),
        );
        $open = $this->fixtures()->log(
            $run,
            CallPhase::Batch,
            2,
            1,
            'req b',
            new \DateTimeImmutable('2026-08-08T10:00:06Z'),
        );
        $this->entityManager()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        $entries = $payload['entries'];
        self::assertIsArray($entries);
        self::assertSame(
            [
                'id' => $finished->getId(),
                'runId' => $run->getId(),
                'phase' => 'batch',
                'batchNumber' => 1,
                'attempt' => 1,
                'verdict' => 'usable',
                'requestBytes' => \strlen('req a'),
                'responseBytes' => \strlen('done text'),
                'wireBytes' => 1_900_000,
                'createdAt' => (new \DateTimeImmutable('2026-08-08T10:00:00Z'))->format(\DATE_ATOM),
                'finishedAt' => (new \DateTimeImmutable('2026-08-08T10:00:05Z'))->format(\DATE_ATOM),
                'errorDetail' => null,
                'finishReason' => 'stop',
                'adjustment' => null,
                'streamingText' => null,
            ],
            $entries[0],
        );
        self::assertSame(
            [
                'id' => $open->getId(),
                'runId' => $run->getId(),
                'phase' => 'batch',
                'batchNumber' => 2,
                'attempt' => 1,
                'verdict' => null,
                'requestBytes' => \strlen('req b'),
                'responseBytes' => 0,
                'wireBytes' => 0,
                'createdAt' => (new \DateTimeImmutable('2026-08-08T10:00:06Z'))->format(\DATE_ATOM),
                'finishedAt' => null,
                'errorDetail' => null,
                'finishReason' => null,
                'adjustment' => null,
                'streamingText' => '',
            ],
            $entries[1],
        );
        $run = $payload['run'];
        self::assertIsArray($run);
        self::assertSame(RunStatus::Pending->value, $run['status']);
        self::assertSame(0, $run['attempts']);
    }

    public function testListIsEmptyForAUserWithoutLogs(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('debug-log-empty@example.test'));

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['entries' => [], 'run' => null, 'runs' => []],
            $this->payload($client),
        );
    }

    public function testListCarriesTheLatestRunsStatusAndRetryCounters(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-run-summary@example.test');
        $run = $this->fixtures()->createRun($user);
        $run->snapshot(RecommendationEngineKind::Llm, [[1]]);
        $run->getRunningCallAttempts()->recordInvalidReply('bad reply');
        $run->fail('The model did not return a usable ranking.', new \DateTimeImmutable('2026-08-08T10:05:00Z'));
        $this->entityManager()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        $runSummary = $this->payload($client)['run'];
        self::assertSame(
            [
                'kind' => 'for_you',
                'id' => $run->getId(),
                'status' => 'failed',
                'error' => 'The model did not return a usable ranking.',
                'attempts' => 1,
                'maxAttempts' => 3,
                'transportFailures' => 0,
                'maxTransportFailures' => 3,
                'createdAt' => (new \DateTimeImmutable('2026-08-08T10:00:00Z'))->format(\DATE_ATOM),
                'completedAt' => (new \DateTimeImmutable('2026-08-08T10:05:00Z'))->format(\DATE_ATOM),
            ],
            $runSummary,
        );
    }

    public function testListNamesTheRetainedRunsAndDefaultsToTheNewest(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-run-list@example.test');
        $older = $this->fixtures()->createRun($user);
        $this->fixtures()->log($older, CallPhase::Batch, 1, 1, 'older request');
        $newer = $this->fixtures()->createRun($user);
        $this->fixtures()->log($newer, CallPhase::Batch, 1, 1, 'newer request');
        $this->entityManager()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, runs: list<array<string, mixed>>} $payload */
        $payload = $this->payload($client);
        self::assertSame(
            [
                [
                    'kind' => 'for_you',
                    'id' => $newer->getId(),
                    'status' => 'pending',
                    'createdAt' => $newer->getCreatedAt()->format(\DATE_ATOM),
                ],
                [
                    'kind' => 'for_you',
                    'id' => $older->getId(),
                    'status' => 'pending',
                    'createdAt' => $older->getCreatedAt()->format(\DATE_ATOM),
                ],
            ],
            $payload['runs'],
        );
        self::assertSame([$newer->getId()], array_column($payload['entries'], 'runId'));
    }

    public function testListReturnsTheRequestedRunsRows(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-run-pick@example.test');
        $older = $this->fixtures()->createRun($user);
        $this->fixtures()->log($older, CallPhase::Batch, 1, 1, 'older request');
        $newer = $this->fixtures()->createRun($user);
        $this->fixtures()->log($newer, CallPhase::Batch, 1, 1, 'newer request');
        $this->entityManager()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log?run=' . $older->getId(), server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client);
        self::assertSame([$older->getId()], array_column($payload['entries'], 'runId'));
        self::assertSame(
            $older->getCreatedAt()->format(\DATE_ATOM),
            $payload['run']['createdAt'],
        );
    }

    /**
     * A selection the retention window has since dropped -- or another
     * account's run id -- lands on the newest run rather than on an empty
     * panel with no explanation.
     */
    public function testAnUnknownRunFallsBackToTheNewest(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-run-stale@example.test');
        $run = $this->fixtures()->createRun($user);
        $this->fixtures()->log($run, CallPhase::Batch, 1, 1, 'request');
        $this->entityManager()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log?run=999999', server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>} $payload */
        $payload = $this->payload($client);
        self::assertSame([$run->getId()], array_column($payload['entries'], 'runId'));
    }

    public function testDetailReturnsFullBodies(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-detail@example.test');
        $run = $this->fixtures()->createRun($user);
        $log = $this->fixtures()->log($run, CallPhase::Batch, 1, 1, 'req');
        $this->fixtures()->settleLog(
            $log,
            'res',
            new CallOutcome(
                CallVerdict::Usable,
                4_096,
                new \DateTimeImmutable('2026-08-08T10:00:05Z'),
                'length',
            ),
        );
        $this->entityManager()->flush();
        $id = $log->getId();
        self::assertNotNull($id);

        $client->request('GET', '/api/recommendations/runs/debug-log/' . $id, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'id' => $id,
                'phase' => 'batch',
                'batchNumber' => 1,
                'attempt' => 1,
                'verdict' => 'usable',
                'requestBody' => 'req',
                'responseText' => 'res',
                'wireBytes' => 4_096,
                'finishReason' => 'length',
            ],
            $this->payload($client),
        );
    }

    public function testDetailOfAnotherUsersRowIs404ProblemJson(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('debug-log-detail-mine@example.test'));
        $otherUser = $this->factory()->create('debug-log-detail-theirs@example.test');
        $theirRun = $this->fixtures()->createRun($otherUser);
        $theirLog = $this->fixtures()->log($theirRun, CallPhase::Batch, 1, 1, 'req');
        $this->entityManager()->flush();
        $id = $theirLog->getId();
        self::assertNotNull($id);

        $client->request('GET', '/api/recommendations/runs/debug-log/' . $id, server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            '"detail":"No such debug log entry."',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testPickerListsRetainedRunsOfEveryKindNewestFirst(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-every-kind@example.test');
        $forYouRun = $this->fixtures()->createRun($user);
        $profileRun = $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $storyRun = $this->persistStoryRunAt($user, new \DateTimeImmutable('2026-08-08T11:00:00Z'));
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                [
                    'kind' => 'story',
                    'id' => $storyRun->getId(),
                    'status' => null,
                    'createdAt' => '2026-08-08T11:00:00+00:00',
                ],
                [
                    'kind' => 'for_you',
                    'id' => $forYouRun->getId(),
                    'status' => 'pending',
                    'createdAt' => '2026-08-08T10:00:00+00:00',
                ],
                [
                    'kind' => 'profile',
                    'id' => $profileRun->getId(),
                    'status' => 'pending',
                    'createdAt' => '2026-08-08T10:00:00+00:00',
                ],
            ],
            $this->payload($client)['runs'],
        );
    }

    public function testDefaultsToNewestForYouRun(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-default-for-you@example.test');
        $forYouRun = $this->fixtures()->createRun($user);
        $this->fixtures()->log($forYouRun, CallPhase::Batch, 1, 1, 'for you request');
        $this->storyRunWithOneRow($user, new \DateTimeImmutable('2026-08-08T11:00:00Z'));
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client);
        self::assertSame('for_you', $payload['run']['kind']);
        self::assertSame($forYouRun->getId(), $payload['run']['id']);
        self::assertSame(['batch'], array_column($payload['entries'], 'phase'));
    }

    public function testDefaultsToNewestRunOfAnyKindWithoutForYouRuns(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-default-any@example.test');
        $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $storyRun = $this->storyRunWithOneRow($user, new \DateTimeImmutable('2026-08-08T11:00:00Z'));
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client);
        self::assertSame('story', $payload['run']['kind']);
        self::assertSame($storyRun->getId(), $payload['run']['id']);
        self::assertSame(['story_title'], array_column($payload['entries'], 'phase'));
    }

    public function testRequestedStoryRunShowsItsCalls(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-story-pick@example.test');
        $forYouRun = $this->fixtures()->createRun($user);
        $this->fixtures()->log($forYouRun, CallPhase::Batch, 1, 1, 'for you request');
        $storyRun = $this->storyRunWithOneRow($user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE . '?kind=story&run=' . $storyRun->getId(), server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client);
        self::assertSame(['story_title'], array_column($payload['entries'], 'phase'));
        self::assertSame([null], array_column($payload['entries'], 'streamingText'));
        self::assertSame(
            [
                'kind' => 'story',
                'id' => $storyRun->getId(),
                'status' => null,
                'error' => null,
                'attempts' => null,
                'maxAttempts' => null,
                'transportFailures' => null,
                'maxTransportFailures' => null,
                'createdAt' => '2026-08-08T09:00:00+00:00',
                'completedAt' => null,
            ],
            $payload['run'],
        );
    }

    public function testRequestedProfileRunShowsItsCalls(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-profile-pick@example.test');
        $forYouRun = $this->fixtures()->createRun($user);
        $this->fixtures()->log($forYouRun, CallPhase::Batch, 1, 1, 'for you request');
        $profileRun = $this->persistProfileRunAt($user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $this->entityManager()->persist(RecommendationRunLog::forProfileRun(
            $profileRun,
            1,
            'profile request',
            new \DateTimeImmutable('2026-08-08T09:00:05Z'),
        ));
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE . '?kind=profile&run=' . $profileRun->getId(), server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client);
        self::assertSame(['distill'], array_column($payload['entries'], 'phase'));
        self::assertSame([''], array_column($payload['entries'], 'streamingText'));
        self::assertSame(
            [
                'kind' => 'profile',
                'id' => $profileRun->getId(),
                'status' => 'pending',
                'error' => null,
                'attempts' => 0,
                'maxAttempts' => ProfileRun::MAX_ATTEMPTS,
                'transportFailures' => 0,
                'maxTransportFailures' => ProfileRun::MAX_TRANSPORT_FAILURES,
                'createdAt' => '2026-08-08T09:00:00+00:00',
                'completedAt' => null,
            ],
            $payload['run'],
        );
    }

    public function testRunWithoutKindStillMeansForYou(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-no-kind@example.test');
        $older = $this->fixtures()->createRun($user);
        $this->fixtures()->log($older, CallPhase::Batch, 1, 1, 'older request');
        $newer = $this->fixtures()->createRun($user);
        $this->fixtures()->log($newer, CallPhase::Batch, 1, 1, 'newer request');
        $this->storyRunWithOneRow($user, new \DateTimeImmutable('2026-08-08T11:00:00Z'));
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE . '?run=' . $older->getId(), server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client);
        self::assertSame('for_you', $payload['run']['kind']);
        self::assertSame($older->getId(), $payload['run']['id']);
        self::assertSame([$older->getId()], array_column($payload['entries'], 'runId'));
    }

    public function testAStoryIdThatOnlyAForYouRunCarriesFallsBackToTheDefault(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-kind-collision@example.test');
        $older = $this->fixtures()->createRun($user);
        $this->fixtures()->log($older, CallPhase::Batch, 1, 1, 'older request');
        $newer = $this->fixtures()->createRun($user);
        $this->fixtures()->log($newer, CallPhase::Batch, 1, 1, 'newer request');
        $this->entityManager()->flush();

        $client->request('GET', self::ROUTE . '?kind=story&run=' . $older->getId(), server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client);
        self::assertSame('for_you', $payload['run']['kind']);
        self::assertSame($newer->getId(), $payload['run']['id']);
        self::assertSame([$newer->getId()], array_column($payload['entries'], 'runId'));
    }

    public function testStoryRunEntryIsServed(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('debug-log-story-entry@example.test');
        $row = $this->storyRowFor($user);

        $client->request('GET', self::ROUTE . '/' . $row->requireId(), server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('story_title', $payload['phase']);
        self::assertSame('story request', $payload['requestBody']);
    }

    public function testStoryRunEntryOfAnotherUserIs404(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('debug-log-story-entry-mine@example.test'));
        $row = $this->storyRowFor($this->factory()->create('debug-log-story-entry-theirs@example.test'));

        $client->request('GET', self::ROUTE . '/' . $row->requireId(), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function persistProfileRunAt(User $user, \DateTimeImmutable $createdAt): ProfileRun
    {
        $profileRun = new ProfileRun($user, ProfileRunTrigger::Manual, $createdAt);
        $this->entityManager()->persist($profileRun);

        return $profileRun;
    }

    private function persistStoryRunAt(User $user, \DateTimeImmutable $createdAt): StoryAiRun
    {
        $storyRun = new StoryAiRun($user, StoryAiTask::Title, $createdAt);
        $this->entityManager()->persist($storyRun);

        return $storyRun;
    }

    private function storyRunWithOneRow(User $user, \DateTimeImmutable $createdAt): StoryAiRun
    {
        $storyRun = $this->persistStoryRunAt($user, $createdAt);
        $this->settledStoryRow($storyRun, $createdAt);

        return $storyRun;
    }

    private function storyRowFor(User $user): RecommendationRunLog
    {
        $createdAt = new \DateTimeImmutable('2026-08-08T09:00:00Z');
        return $this->settledStoryRow($this->persistStoryRunAt($user, $createdAt), $createdAt);
    }

    private function settledStoryRow(StoryAiRun $storyRun, \DateTimeImmutable $createdAt): RecommendationRunLog
    {
        $row = RecommendationRunLog::forStoryAiRun($storyRun, 'story request', $createdAt);
        $this->entityManager()->persist($row);
        $this->fixtures()->settleLog(
            $row,
            'A story title',
            new CallOutcome(CallVerdict::Usable, 64, $createdAt->modify('+2 seconds'), 'stop'),
        );

        return $row;
    }
}
