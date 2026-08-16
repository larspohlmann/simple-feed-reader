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
 * The read side of the run cost history (#409): the overview the card opens
 * on -- one summary per calendar month, the newest month's own runs, and the
 * all-time total banked over every run the account ever made -- and the
 * month route a reader pages further into. Read-only, so ownership is proven
 * the same way the #309 debug log test proves it -- a second account's run
 * must not leak in, into the month summaries, the newest month's runs or the
 * total.
 */
final class RecommendationRunHistoryControllerTest extends WebTestCase
{
    private const string HISTORY_ROUTE = '/api/recommendations/runs/history';

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

    public function testOverviewAnswersWithTheAccountsMonthLatestRunsAndTheAllTimeTotal(): void
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
        $this->fixtures()->priceRun($older, 1_000);

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
        $this->fixtures()->priceRun($newer, 2_000);

        $otherUser = $this->em()->getRepository(User::class)->find($otherUser->getId());
        self::assertInstanceOf(User::class, $otherUser);
        $theirRun = $this->fixtures()->createRun($otherUser);
        $theirRun->stampProvider('example.test', 'their-model');
        $theirRun->snapshot([[1]]);
        $theirRun->recordBatchWinners([]);
        $theirRun->complete(new \DateTimeImmutable('2026-08-16 09:05:10'));
        $this->em()->flush();
        $this->fixtures()->priceRun($theirRun, 9_999);

        $client->request('GET', self::HISTORY_ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame(3_000, $payload['totalCostNanoCredits']);
        self::assertSame(
            [['month' => '2026-08', 'runCount' => 2, 'costNanoCredits' => 3_000]],
            $payload['months'],
        );
        /** @var array<string, mixed> $latest */
        $latest = $payload['latest'];
        self::assertSame('2026-08', $latest['month']);
        self::assertNull($latest['nextCursor']);
        /** @var list<array<string, mixed>> $runs */
        $runs = $latest['runs'];
        self::assertCount(2, $runs);
        self::assertSame($newer->getId(), $runs[0]['id']);
        self::assertSame($older->getId(), $runs[1]['id']);
        self::assertSame('openrouter.ai', $runs[0]['providerHost']);
        self::assertSame(2_000, $runs[0]['costNanoCredits']);
        self::assertSame(1_000, $runs[1]['costNanoCredits']);
    }

    /**
     * The newest month's page is capped at
     * RecommendationRunHistoryRepository::HISTORY_LIMIT (#409) even though
     * the all-time total above it is not -- an unasserted cap is an unkilled
     * mutant on both the constant's value and the view's truncation.
     *
     * Seeds through RecommendationRunFixtures::persistRunAt() rather than
     * createRun() -- that method's date is shared with unrelated suites, so a
     * test that owns its own dates cannot be broken by a change made to
     * satisfy one of them.
     */
    public function testCapsTheNewestMonthAtTheLimitKeepingTheNewestRunsAndReportsANextCursor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-cap@example.test');

        $runCount = RecommendationRunHistoryRepository::HISTORY_LIMIT + 1;
        $seededRuns = [];
        $fixtures = $this->fixtures();
        for ($minute = 0; $minute < $runCount; $minute++) {
            $createdAt = new \DateTimeImmutable(sprintf('2026-08-01 00:%02d:00', $minute));
            $seededRuns[] = $fixtures->persistRunAt($user, $createdAt);
        }
        $seededIds = array_map(static fn (RecommendationRun $run): ?int => $run->getId(), $seededRuns);

        $client->request('GET', self::HISTORY_ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var array<string, mixed> $latest */
        $latest = $payload['latest'];
        /** @var list<array<string, mixed>> $returnedRuns */
        $returnedRuns = $latest['runs'];
        self::assertCount(RecommendationRunHistoryRepository::HISTORY_LIMIT, $returnedRuns);
        // Newest first, with the single oldest seeded run dropped by the cap.
        self::assertSame(array_reverse(\array_slice($seededIds, 1)), array_column($returnedRuns, 'id'));
        self::assertSame($seededIds[1], $latest['nextCursor']);

        $client->request('GET', self::HISTORY_ROUTE . '/2026-08?before=' . $seededIds[1], server: $headers);

        self::assertResponseIsSuccessful();
        $nextPage = $this->payload($client->getResponse());
        /** @var list<array<string, mixed>> $nextPageRuns */
        $nextPageRuns = $nextPage['runs'];
        self::assertSame([$seededIds[0]], array_column($nextPageRuns, 'id'));
        self::assertNull($nextPage['nextCursor']);
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

        $client->request('GET', self::HISTORY_ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var array<string, mixed> $latest */
        $latest = $payload['latest'];
        /** @var list<array<string, mixed>> $runs */
        $runs = $latest['runs'];
        self::assertSame('running', $runs[0]['status']);
        self::assertNull($runs[0]['completedAt']);
        self::assertNull($runs[0]['durationSeconds']);
    }

    public function testAnAccountThatNeverRanGetsAnEmptyOverviewAndNoTotal(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('run-history-empty@example.test');

        $client->request('GET', self::HISTORY_ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame([], $payload['months']);
        self::assertNull($payload['latest']);
        self::assertNull($payload['totalCostNanoCredits']);
    }

    public function testRefusesAnAnonymousRequest(): void
    {
        $client = self::createClient();

        $client->request('GET', self::HISTORY_ROUTE);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Runs in two different months produce two `months` entries with their
     * own counts and totals, and `latest` opens on the newer of the two --
     * not on whichever happens to sort first in the array. This does not by
     * itself rule out a wall-clock implementation, since both months here
     * are 2026 and the newer one happens to be the current calendar month
     * too; {@see testLatestOpensOnTheNewestMonthWithRunsEvenYearsInThePast}
     * is the test that rules that out.
     */
    public function testRunsInTwoDifferentMonthsProduceTwoMonthEntriesWithLatestTheNewerOne(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-two-months@example.test');

        $julyOne = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-07-10 09:00:00'));
        $julyTwo = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-07-20 09:00:00'));
        $this->fixtures()->priceRun($julyOne, 500);
        $this->fixtures()->priceRun($julyTwo, 700);

        $user = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);
        $august = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-05 09:00:00'));
        $this->fixtures()->priceRun($august, 1_200);

        $client->request('GET', self::HISTORY_ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame(
            [
                ['month' => '2026-08', 'runCount' => 1, 'costNanoCredits' => 1_200],
                ['month' => '2026-07', 'runCount' => 2, 'costNanoCredits' => 1_200],
            ],
            $payload['months'],
        );
        /** @var array<string, mixed> $latest */
        $latest = $payload['latest'];
        self::assertSame('2026-08', $latest['month']);
        /** @var list<array<string, mixed>> $latestRuns */
        $latestRuns = $latest['runs'];
        self::assertSame([$august->getId()], array_column($latestRuns, 'id'));
    }

    /**
     * `latest` is the newest month that HAS runs, not the calendar month the
     * server's clock currently reads (#409) -- an account whose only runs
     * are years in the past still opens on that month. 2024-03 can never be
     * "now" for this test, so unlike the two-months test above, a wall-clock
     * implementation (`new \DateTimeImmutable('now', $viewer->zone)`) fails
     * this one: it would answer with the current month instead of 2024-03,
     * and `latest['runs']` would then be empty because no run exists in it.
     */
    public function testLatestOpensOnTheNewestMonthWithRunsEvenYearsInThePast(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-past-month@example.test');

        $only = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2024-03-14 09:00:00'));

        $client->request('GET', self::HISTORY_ROUTE, server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame(
            [['month' => '2024-03', 'runCount' => 1, 'costNanoCredits' => null]],
            $payload['months'],
        );
        /** @var array<string, mixed> $latest */
        $latest = $payload['latest'];
        self::assertSame('2024-03', $latest['month']);
        /** @var list<array<string, mixed>> $latestRuns */
        $latestRuns = $latest['runs'];
        self::assertSame([$only->getId()], array_column($latestRuns, 'id'));
    }

    public function testTheMonthRouteReturnsOnlyThatMonthsRuns(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-month-route@example.test');

        $july = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-07-12 09:00:00'));
        $user = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-12 09:00:00'));

        $client->request('GET', self::HISTORY_ROUTE . '/2026-07', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        self::assertSame('2026-07', $payload['month']);
        /** @var list<array<string, mixed>> $runs */
        $runs = $payload['runs'];
        self::assertSame([$july->getId()], array_column($runs, 'id'));
        self::assertNull($payload['nextCursor']);
    }

    public function testAMonthOutsideTheRouteRequirementIs404(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('run-history-bad-month@example.test');

        $client->request('GET', self::HISTORY_ROUTE . '/2026-13', server: $headers);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * ViewerTimeZone fails soft on an identifier the server's tzdata does not
     * know -- this is a display preference, not a security boundary -- so the
     * request still answers 200, bucketed as if in UTC.
     */
    public function testAnUnrecognisedTimezoneStillAnswersOkBucketedInUtc(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-bad-tz@example.test');

        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-12 09:00:00'));

        $client->request('GET', self::HISTORY_ROUTE . '?tz=Not/AZone', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var array<string, mixed> $latest */
        $latest = $payload['latest'];
        self::assertSame('2026-08', $latest['month']);
    }

    /**
     * `?before=` pages backwards within a month, the same keyset the
     * repository proves in isolation -- pinned again here through the route,
     * where the query parameter is parsed.
     */
    public function testBeforeCursorPagesWithinAMonth(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-history-cursor@example.test');

        $oldest = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-01 09:00:00'));
        $user = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);
        $middle = $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-02 09:00:00'));
        $user = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $user);
        $this->fixtures()->persistRunAt($user, new \DateTimeImmutable('2026-08-03 09:00:00'));

        $client->request(
            'GET',
            self::HISTORY_ROUTE . '/2026-08?before=' . $middle->getId(),
            server: $headers,
        );

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse());
        /** @var list<array<string, mixed>> $runs */
        $runs = $payload['runs'];
        self::assertSame([$oldest->getId()], array_column($runs, 'id'));
        self::assertNull($payload['nextCursor']);
    }
}
