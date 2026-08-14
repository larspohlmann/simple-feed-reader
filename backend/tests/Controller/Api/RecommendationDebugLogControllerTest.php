<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The read side of the recommendation debug view (#309): a cheap list poll
 * and a per-row detail fetch. Both sit behind the same bearer auth as the
 * rest of the API and carry no rate limiter, same stance as `/current`.
 */
final class RecommendationDebugLogControllerTest extends WebTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function fixtures(): RecommendationRunFixtures
    {
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        return new RecommendationRunFixtures($this->em(), $cipher);
    }

    public function testListReturnsEntriesWithStreamingTextOnlyForTheOpenCall(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('debug-log-list@example.test');
        $run = $this->fixtures()->createRun($user);
        $finished = $this->fixtures()->log(
            $run,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'req a',
            new \DateTimeImmutable('2026-08-08T10:00:00Z'),
        );
        $finished->finish(
            'done text',
            RecommendationRunLog::VERDICT_USABLE,
            1_900_000,
            'stop',
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
        $open = $this->fixtures()->log(
            $run,
            RecommendationRunLog::PHASE_BATCH,
            2,
            1,
            'req b',
            new \DateTimeImmutable('2026-08-08T10:00:06Z'),
        );
        $this->em()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
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
                'streamingText' => '',
            ],
            $entries[1],
        );
        $run = $payload['run'];
        self::assertIsArray($run);
        self::assertSame(RecommendationRun::STATUS_PENDING, $run['status']);
        self::assertSame(0, $run['attempts']);
    }

    public function testListIsEmptyForAUserWithoutLogs(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('debug-log-empty@example.test');

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['entries' => [], 'run' => null, 'runs' => []],
            $this->payload($client->getResponse()),
        );
    }

    public function testListCarriesTheLatestRunsStatusAndRetryCounters(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('debug-log-run-summary@example.test');
        $run = $this->fixtures()->createRun($user);
        $run->snapshot([[1]]);
        $run->recordInvalidReply('bad reply');
        $run->fail('The model did not return a usable ranking.', new \DateTimeImmutable('2026-08-08T10:05:00Z'));
        $this->em()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        $runSummary = $this->payload($client->getResponse())['run'];
        self::assertSame(
            [
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

    /**
     * The panel reads one run at a time, so the payload names the runs it may
     * switch to (#401) -- newest first, and by default the newest is the one
     * whose rows come with it.
     */
    public function testListNamesTheRetainedRunsAndDefaultsToTheNewest(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('debug-log-run-list@example.test');
        $older = $this->fixtures()->createRun($user);
        $this->fixtures()->log($older, RecommendationRunLog::PHASE_BATCH, 1, 1, 'older request');
        $newer = $this->fixtures()->createRun($user);
        $this->fixtures()->log($newer, RecommendationRunLog::PHASE_BATCH, 1, 1, 'newer request');
        $this->em()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log', server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, runs: list<array<string, mixed>>} $payload */
        $payload = $this->payload($client->getResponse());
        self::assertSame(
            [
                [
                    'id' => $newer->getId(),
                    'status' => 'pending',
                    'createdAt' => $newer->getCreatedAt()->format(\DATE_ATOM),
                ],
                [
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
        [$headers, $user] = $this->auth('debug-log-run-pick@example.test');
        $older = $this->fixtures()->createRun($user);
        $this->fixtures()->log($older, RecommendationRunLog::PHASE_BATCH, 1, 1, 'older request');
        $newer = $this->fixtures()->createRun($user);
        $this->fixtures()->log($newer, RecommendationRunLog::PHASE_BATCH, 1, 1, 'newer request');
        $this->em()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log?run=' . $older->getId(), server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>, run: array<string, mixed>} $payload */
        $payload = $this->payload($client->getResponse());
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
        [$headers, $user] = $this->auth('debug-log-run-stale@example.test');
        $run = $this->fixtures()->createRun($user);
        $this->fixtures()->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'request');
        $this->em()->flush();

        $client->request('GET', '/api/recommendations/runs/debug-log?run=999999', server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array<string, mixed>>} $payload */
        $payload = $this->payload($client->getResponse());
        self::assertSame([$run->getId()], array_column($payload['entries'], 'runId'));
    }

    public function testDetailReturnsFullBodies(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('debug-log-detail@example.test');
        $run = $this->fixtures()->createRun($user);
        $log = $this->fixtures()->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req');
        $log->finish(
            'res',
            RecommendationRunLog::VERDICT_USABLE,
            4_096,
            'length',
            new \DateTimeImmutable('2026-08-08T10:00:05Z'),
        );
        $this->em()->flush();
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
            $this->payload($client->getResponse()),
        );
    }

    public function testDetailOfAnotherUsersRowIs404ProblemJson(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('debug-log-detail-mine@example.test');
        [, $otherUser] = $this->auth('debug-log-detail-theirs@example.test');
        $theirRun = $this->fixtures()->createRun($otherUser);
        $theirLog = $this->fixtures()->log($theirRun, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req');
        $this->em()->flush();
        $id = $theirLog->getId();
        self::assertNotNull($id);

        $client->request('GET', '/api/recommendations/runs/debug-log/' . $id, server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }
}
