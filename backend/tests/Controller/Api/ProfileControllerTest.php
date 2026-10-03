<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\ProfileRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Repository\ProfileRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @phpstan-type State array{
 *     profileText: ?string, generatedAt: ?string, intervalHours: ?int, intervalChoices: list<?int>,
 *     connectionId: ?int, connection: ?array{id: int}, candidates: list<array{id: int}>, keptCap: int, viewedCap: int,
 *     bounds: array{keptCap: array{min: int, max: int}}, debugEnabled: bool
 * }
 */
final class ProfileControllerTest extends ApiTestCase
{
    private const string URI = '/api/me/ai/profile';

    /** Must match framework.rate_limiter.ai_profile_runs.limit in rate_limiter.yaml. */
    private const int START_BUDGET = 10;

    protected function setUp(): void
    {
        // The limiter counts in a filesystem pool that outlives the test, so a prior case's spend would trip a 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testANewAccountReadsTheDefaultsWithItsActiveConnection(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-show@example.test');
        $active = $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        $state = $this->state($client);
        self::assertNull($state['profileText']);
        self::assertNull($state['generatedAt']);
        self::assertNull($state['intervalHours']);
        self::assertSame([null, 6, 12, 24, 48, 168], $state['intervalChoices']);
        self::assertNull($state['connectionId']);
        self::assertSame($active->getId(), $state['connection']['id'] ?? null);
        self::assertSame([$active->getId()], array_column($state['candidates'], 'id'));
        self::assertSame(40, $state['keptCap']);
        self::assertSame(80, $state['viewedCap']);
        self::assertSame(['min' => 0, 'max' => 500], $state['bounds']['keptCap']);
        self::assertFalse($state['debugEnabled']);
    }

    public function testSavingPersistsTheScheduleTheConnectionAndTheCaps(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-save@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');
        $chosen = $this->fixtures()->seedInactiveAiSettingsFor($user, 'gpt-4o');

        $this->put(
            $client,
            $headers,
            ['intervalHours' => 48, 'connectionId' => $chosen->getId(), 'keptCap' => 7, 'viewedCap' => 9],
        );
        self::assertResponseIsSuccessful();
        $client->request('GET', self::URI, server: $headers);

        $state = $this->state($client);
        self::assertSame(48, $state['intervalHours']);
        self::assertSame($chosen->getId(), $state['connectionId']);
        self::assertSame($chosen->getId(), $state['connection']['id'] ?? null);
        self::assertSame(7, $state['keptCap']);
        self::assertSame(9, $state['viewedCap']);
    }

    public function testAConnectionThatCannotBuildAProfileIsRefused(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-jev@example.test');
        $jev = $this->fixtures()->seedReadyAiSettingsFor($user, 'jev-latest');

        $this->put(
            $client,
            $headers,
            ['intervalHours' => null, 'connectionId' => $jev->getId(), 'keptCap' => 40, 'viewedCap' => 80],
        );

        $this->assertRejected($client, 422);
        self::assertSame('profile_connection_rejected', $this->payload($client)['type']);
    }

    public function testAnotherAccountsConnectionReadsAsMissing(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('profile-owner@example.test');
        [, $stranger] = $this->auth('profile-stranger@example.test');
        $theirs = $this->fixtures()->seedReadyAiSettingsFor($stranger, 'gpt-4o');

        $this->put(
            $client,
            $headers,
            ['intervalHours' => null, 'connectionId' => $theirs->getId(), 'keptCap' => 40, 'viewedCap' => 80],
        );

        $this->assertRejected($client, 404);
    }

    public function testAScheduleOutsideTheChoicesIsAValidationError(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-interval@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        $this->put(
            $client,
            $headers,
            ['intervalHours' => 5, 'connectionId' => null, 'keptCap' => 40, 'viewedCap' => 80],
        );

        $this->assertRejected($client, 422);
    }

    public function testStartingOpensAManualRunAndASecondStartReturnsIt(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('profile-start@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        $client->request('POST', self::URI . '/runs', server: $headers);
        self::assertResponseIsSuccessful();
        $first = $this->payload($client);
        $client->request('POST', self::URI . '/runs', server: $headers);

        self::assertSame('pending', $first['status']);
        self::assertSame('manual', $first['trigger']);
        self::assertSame($first['id'], $this->payload($client)['id']);
        $client->request('GET', self::URI . '/runs/current', server: $headers);
        self::assertSame($first['id'], $this->payload($client)['id']);
    }

    public function testStartingWithoutAUsableConnectionIsRefused(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->auth('profile-start-jev@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'jev-latest');

        $client->request('POST', self::URI . '/runs', server: $headers);

        $this->assertRejected($client, 422);
        self::assertSame('profile_connection_missing', $this->payload($client)['type']);
    }

    public function testAnAccountThatNeverRanReadsNoCurrentRun(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('profile-current-none@example.test');

        $client->request('GET', self::URI . '/runs/current', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('none', $this->payload($client)['status']);
        self::assertNull($this->payload($client)['id']);
    }

    public function testStartingWhileARunIsActiveSpendsNoBudget(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('profile-active-free@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        for ($click = 1; $click <= self::START_BUDGET + 2; ++$click) {
            $client->request('POST', self::URI . '/runs', server: $headers);
            self::assertResponseIsSuccessful(sprintf('Click %d returned the active run.', $click));
        }
    }

    public function testAStartRefusedForWantOfAConnectionSpendsNoBudget(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('profile-refused-free@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'jev-latest');

        for ($click = 1; $click <= self::START_BUDGET + 1; ++$click) {
            $client->request('POST', self::URI . '/runs', server: $headers);
            $this->assertRejected($client, 422);
        }
    }

    public function testAStartBeyondTheHoursBudgetIsRateLimited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('profile-budget@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        for ($spent = 1; $spent <= self::START_BUDGET; ++$spent) {
            $client->request('POST', self::URI . '/runs', server: $headers);
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent));
            $this->finishLatestRunOf($user);
        }
        $client->request('POST', self::URI . '/runs', server: $headers);

        $this->assertRejected($client, 429);
        self::assertSame('rate_limited', $this->payload($client)['type']);
        // The next token frees in 6 minutes of an hour's window, 90 seconds of ai_recommendation_starts' quarter hour.
        self::assertGreaterThan(5 * 60, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testTheDebugLogEntryServesAProfileRowToItsOwnerOnly(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $owner] = $this->auth('profile-row-owner@example.test');
        [$strangerHeaders] = $this->auth('profile-row-stranger@example.test');
        $row = $this->profileRunRowOf($owner, '{"profile-request":1}');
        $uri = '/api/recommendations/runs/debug-log/' . $row->getId();

        $client->request('GET', $uri, server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('{"profile-request":1}', $this->payload($client)['requestBody']);
        $client->request('GET', $uri, server: $strangerHeaders);

        $this->assertRejected($client, 404);
    }

    public function testTheLogListsTheNewestProfileRunsCalls(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$headers, $owner] = $this->auth('profile-log-rows@example.test');
        $row = $this->profileRunRowOf($owner, '{}');

        $client->request('GET', self::URI . '/runs/current/log', server: $headers);

        self::assertResponseIsSuccessful();
        /** @var array{entries: list<array{id: int}>} $payload */
        $payload = $this->payload($client);
        self::assertSame([$row->getId()], array_column($payload['entries'], 'id'));
    }

    public function testTheLogOfAnAccountWithoutProfileRunsIsEmpty(): void
    {
        $client = static::createClient();
        [$headers] = $this->auth('profile-log@example.test');

        $client->request('GET', self::URI . '/runs/current/log', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['entries' => []], $this->payload($client));
    }

    public function testEveryRouteNeedsAToken(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URI);

        self::assertResponseStatusCodeSame(401);
    }

    /** @return State */
    private function state(KernelBrowser $client): array
    {
        /** @var State $state */
        $state = $this->payload($client);

        return $state;
    }

    private function finishLatestRunOf(User $user): void
    {
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = self::getContainer()->get(ProfileRunRepository::class);
        $latest = $profileRuns->findLatestForUser($user);
        self::assertNotNull($latest);
        $latest->fail('finished by the test', new \DateTimeImmutable('2026-10-03 09:10:00'));
        $this->entityManager()->flush();
    }

    private function profileRunRowOf(User $owner, string $requestBody): RecommendationRunLog
    {
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $row = RecommendationRunLog::forProfileRun(
            $profileRun,
            1,
            $requestBody,
            new \DateTimeImmutable('2026-10-03 09:00:05'),
        );
        $this->entityManager()->persist($profileRun);
        $this->entityManager()->persist($row);
        $this->entityManager()->flush();

        return $row;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     */
    private function put(KernelBrowser $client, array $headers, array $body): void
    {
        $client->request(
            'PUT',
            self::URI,
            server: array_merge($headers, ['CONTENT_TYPE' => 'application/json']),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array{0: array<string, string>, 1: User} */
    private function auth(string $email): array
    {
        $user = $this->factory()->create($email);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function fixtures(): RecommendationRunFixtures
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);

        return new RecommendationRunFixtures($entityManager, $cipher);
    }
}
