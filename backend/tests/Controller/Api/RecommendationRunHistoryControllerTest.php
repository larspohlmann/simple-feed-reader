<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The read side of the run cost history (#409): the account's newest runs
 * with what each one cost, and the all-time total banked over every run it
 * ever made. Read-only, so ownership is proven the same way the #309 debug
 * log test proves it — a second account's run must not leak in, either into
 * the list or into the total.
 */
final class RecommendationRunHistoryControllerTest extends WebTestCase
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

    /**
     * The provider price is banked through raw SQL arithmetic in production
     * (RecordedCall::bankUsage(), never through the entity — see
     * ProviderUsage's class doc), so a fixture that wants a priced run has to
     * write the same column the same way rather than call a setter that does
     * not exist.
     */
    private function priceRun(RecommendationRun $run, int $costNanoCredits): void
    {
        $id = $run->getId();
        self::assertNotNull($id);

        $this->em()->getConnection()->executeStatement(
            'UPDATE recommendation_run SET cost_nano_credits = :cost WHERE id = :id',
            ['cost' => $costNanoCredits, 'id' => $id],
        );
        $this->em()->clear();
    }

    public function testAnswersWithTheAccountsRunsNewestFirstAndTheAllTimeTotal(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-mine@example.test');
        [, $otherUser] = $this->auth('run-history-theirs@example.test');

        $older = $this->fixtures()->createRun($user);
        $older->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $older->snapshot([[1]]);
        $older->recordBatchWinners([]);
        $older->complete(new \DateTimeImmutable('2026-08-16 09:00:10'));
        $this->em()->flush();
        $this->priceRun($older, 1_000);

        // priceRun() clears the identity map, so $older and $user must be
        // re-fetched before they are used as Doctrine associations again.
        $user = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);

        $newer = $this->fixtures()->createRun($user);
        $newer->stampProvider('openrouter.ai', 'x-ai/grok-4-fast');
        $newer->snapshot([[1]]);
        $newer->recordBatchWinners([]);
        $newer->complete(new \DateTimeImmutable('2026-08-16 09:05:10'));
        $this->em()->flush();
        $this->priceRun($newer, 2_000);

        $otherUser = $this->em()->getRepository(User::class)->find($otherUser->getId());
        self::assertInstanceOf(User::class, $otherUser);
        $theirRun = $this->fixtures()->createRun($otherUser);
        $theirRun->stampProvider('example.test', 'their-model');
        $theirRun->snapshot([[1]]);
        $theirRun->recordBatchWinners([]);
        $theirRun->complete(new \DateTimeImmutable('2026-08-16 09:05:10'));
        $this->em()->flush();
        $this->priceRun($theirRun, 9_999);

        $client->request('GET', '/api/recommendations/runs/history', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame(3_000, $payload['totalCostNanoCredits']);
        /** @var list<array<string, mixed>> $runs */
        $runs = $payload['runs'];
        self::assertCount(2, $runs);
        self::assertSame($newer->getId(), $runs[0]['id']);
        self::assertSame($older->getId(), $runs[1]['id']);
        self::assertSame('openrouter.ai', $runs[0]['providerHost']);
        self::assertSame(2_000, $runs[0]['costNanoCredits']);
        self::assertSame(1_000, $runs[1]['costNanoCredits']);
    }

    /**
     * The list is capped at RecommendationRunHistoryRepository::HISTORY_LIMIT
     * (#409) even though the all-time total above it is not -- an
     * unasserted cap is an unkilled mutant on both the constant's value and
     * the controller's call site.
     */
    public function testCapsTheHistoryAtTheLimitKeepingTheNewestRuns(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-cap@example.test');

        $runCount = RecommendationRunHistoryRepository::HISTORY_LIMIT + 1;
        $seededRuns = [];
        for ($i = 0; $i < $runCount; $i++) {
            $seededRuns[] = $this->fixtures()->createRun($user);
        }
        $this->em()->flush();
        $seededIds = array_map(static fn (RecommendationRun $run): ?int => $run->getId(), $seededRuns);

        $client->request('GET', '/api/recommendations/runs/history', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var list<array<string, mixed>> $returnedRuns */
        $returnedRuns = $payload['runs'];
        self::assertCount(RecommendationRunHistoryRepository::HISTORY_LIMIT, $returnedRuns);
        // Newest first, with the single oldest seeded run dropped by the cap.
        self::assertSame(array_reverse(\array_slice($seededIds, 1)), array_column($returnedRuns, 'id'));
    }

    /**
     * resume() deliberately does not clear completedAt, so the row really does
     * reach the payload with a RUNNING status beside the timestamp of the
     * attempt that failed. The endpoint must report neither that time nor the
     * duration derived from it — a "47 s" beside a RUNNING badge measures a
     * dead attempt (#409).
     */
    public function testAResumedRunReportsNeitherACompletionTimeNorADuration(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-resumed@example.test');

        $run = $this->fixtures()->createRun($user);
        $run->snapshot([[1]]);
        $run->fail('that provider did not answer', new \DateTimeImmutable('2026-08-08 10:00:47'));
        $run->resume();
        $this->em()->flush();
        // The column keeps the failed attempt's stamp; only the payload hides
        // it. Asserted here so a future "fix" in the entity is caught as the
        // behaviour change it would be.
        self::assertNotNull($run->getCompletedAt());

        $client->request('GET', '/api/recommendations/runs/history', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var list<array<string, mixed>> $runs */
        $runs = $payload['runs'];
        self::assertSame('running', $runs[0]['status']);
        self::assertNull($runs[0]['completedAt']);
        self::assertNull($runs[0]['durationSeconds']);
    }

    public function testAnAccountThatNeverRanGetsAnEmptyHistoryAndNoTotal(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('run-history-empty@example.test');

        $client->request('GET', '/api/recommendations/runs/history', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame([], $payload['runs']);
        self::assertNull($payload['totalCostNanoCredits']);
    }

    public function testRefusesAnAnonymousRequest(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/recommendations/runs/history');

        self::assertResponseStatusCodeSame(401);
    }
}
