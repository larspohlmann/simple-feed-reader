<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ModelNotOfferedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The controller in front of Task 9-11's run state machine. The provider is
 * never really called: StubChatClient stands in for it via the container
 * alias in services_test.yaml, so these cases prove the endpoints' own
 * behaviour — routing, auth, exception mapping, the limiter — without a
 * network.
 */
final class RecommendationRunControllerTest extends WebTestCase
{
    /** Must match framework.rate_limiter.ai_recommendation_starts.limit in rate_limiter.yaml. */
    private const int START_BUDGET = 10;

    /** Must match framework.rate_limiter.ai_recommendations.limit in rate_limiter.yaml. */
    private const int TICK_BUDGET = 90;

    protected function setUp(): void
    {
        // The limiter counts in a FILESYSTEM pool that outlives the run, so a
        // prior case's spend would trip a 429 here too — see
        // AiSettingsControllerTest for the same guard.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

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

    private function seedReadyAiSettings(User $user): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);

        $userId = $user->getId();
        self::assertNotNull($userId);
        $sealed = $cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, 'https://api.example.test/v1', $sealed, '1234', $now);
        $em->persist($settings);
        $settings->chooseModel('m', $now, 32768);
        $em->flush();
    }

    /**
     * A single candidate is enough: the default candidate pool packs it into
     * one batch, which is what the tick sequence below needs.
     */
    private function seedOneCandidateEntry(User $user): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'g1',
            'https://example.com/1',
            'Post 1',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setPublishedAt(new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $em->persist($entry);
        $em->flush();
    }

    private function stubChatClient(): StubChatClient
    {
        $client = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $client);

        return $client;
    }

    /** @return array<string, mixed> */
    private function payload(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function testAnUnauthenticatedTickIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/api/recommendations/runs/tick');

        self::assertResponseStatusCodeSame(401);
    }

    public function testStartingWithoutAiConfiguredIsNotFound(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('run-noai@example.test');

        $client->request('POST', '/api/recommendations/runs', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('ai_not_configured', $this->payload($client->getResponse())['type']);
    }

    public function testStartingAReadyAccountReportsPending(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('run-start@example.test');
        $this->seedReadyAiSettings($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'pending', 'batchesTotal' => null, 'batchesDone' => 0, 'error' => null],
            $this->payload($client->getResponse()),
        );
    }

    public function testTickSequenceRunsThroughToCompletion(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('run-tick@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();

        // The snapshot tick freezes the candidate pool into batches without
        // calling the provider.
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('running', $this->payload($client->getResponse())['status']);

        $entry = self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Entry::class)
            ->findOneBy(['guid' => 'g1']);
        self::assertInstanceOf(Entry::class, $entry);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $entry->getId(), 'reason' => 'a good read']],
        ], \JSON_THROW_ON_ERROR));

        // The provider tick spends the one queued reply and finalizes the
        // single-batch run.
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('completed', $this->payload($client->getResponse())['status']);

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame('completed', $this->payload($client->getResponse())['status']);
    }

    public function testCurrentWithoutAnyRunReportsNone(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('run-current-none@example.test');

        $client->request('GET', '/api/recommendations/runs/current', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['status' => 'none', 'batchesTotal' => null, 'batchesDone' => 0, 'error' => null],
            $this->payload($client->getResponse()),
        );
    }

    /**
     * Every member of the tick action's provider-refusal union, one at a
     * time: dropping any single type from that catch turns its case into an
     * uncaught throw and an opaque 500 instead of the documented 422.
     *
     * @return iterable<string, array{\RuntimeException}>
     */
    public static function providerRefusals(): iterable
    {
        yield 'an address that does not answer' => [new ProviderUnreachableException('down')];
        yield 'a key the provider refuses' => [new CredentialsRejectedException('refused')];
        yield 'a model the provider no longer offers' => [new ModelNotOfferedException('gone')];
    }

    #[DataProvider('providerRefusals')]
    public function testAProviderRefusalDuringATickIsUnprocessable(\RuntimeException $refusal): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('run-refused-' . $refusal::class . '@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();

        $this->stubChatClient()->queueFailure($refusal);

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client->getResponse())['type']);
    }

    /**
     * advance() throws AiNotConfiguredException when a run is already active
     * but the account's provider row has since been removed — a settings
     * change racing an in-flight run. Distinct from start()'s own mapping of
     * the same exception type: this pins that tick() carries the mapping too.
     */
    public function testATickWhoseConfigurationDisappearedIsNotFound(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('run-tick-noai@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $settings = $em->getRepository(AiProviderSettings::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(AiProviderSettings::class, $settings);
        $em->remove($settings);
        $em->flush();

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('ai_not_configured', $this->payload($client->getResponse())['type']);
    }

    /**
     * A stored key that no longer decrypts surfaces during the provider tick,
     * once the run is past its snapshot phase and about to call the model —
     * see AiSettingsControllerTest for the same corruption technique.
     */
    public function testATickWithAnUnreadableStoredKeyIsUnprocessable(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('run-tick-unreadable@example.test');
        $this->seedReadyAiSettings($user);
        $this->seedOneCandidateEntry($user);

        $client->request('POST', '/api/recommendations/runs', server: $headers);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
        self::assertResponseIsSuccessful();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->getConnection()->executeStatement(
            "UPDATE user_ai_settings SET api_key_ciphertext = 'not-a-sealed-key'",
        );
        $em->clear();

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_key_unreadable', $this->payload($client->getResponse())['type']);
    }

    /**
     * Pins that the ai_recommendation_starts budget is actually spent by
     * start(). Every other case only proves the limiter does NOT fire, so a
     * limiter argument bound to the wrong service — it autowires by parameter
     * name — would leave the whole suite green with the endpoint uncapped.
     * The two routes carry different budgets precisely so this case would fail
     * if start() were wired to the loose tick limiter.
     */
    public function testAStartBeyondTheWindowsBudgetIsRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers, $user] = $this->auth('run-budget@example.test');
        $this->seedReadyAiSettings($user);

        for ($spent = 1; $spent <= self::START_BUDGET; ++$spent) {
            $client->request('POST', '/api/recommendations/runs', server: $headers);
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent));
        }

        $client->request('POST', '/api/recommendations/runs', server: $headers);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload($client->getResponse())['type']);
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * The same budget check on the tick action, proven independently of
     * start()'s: with no AI configured, advance() is a harmless no-op
     * ('none') so a run of ticks exercises the limiter alone, with nothing
     * else that could turn a spent budget into a different status code.
     */
    public function testATickBeyondTheWindowsBudgetIsRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers] = $this->auth('run-tick-budget@example.test');

        for ($spent = 1; $spent <= self::TICK_BUDGET; ++$spent) {
            $client->request('POST', '/api/recommendations/runs/tick', server: $headers);
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent));
        }

        $client->request('POST', '/api/recommendations/runs/tick', server: $headers);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload($client->getResponse())['type']);
    }

    public function testCurrentIsNeverRateLimited(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        [$headers] = $this->auth('run-current-unlimited@example.test');

        for ($i = 0; $i <= self::TICK_BUDGET; ++$i) {
            $client->request('GET', '/api/recommendations/runs/current', server: $headers);
            self::assertResponseIsSuccessful();
        }
    }
}
