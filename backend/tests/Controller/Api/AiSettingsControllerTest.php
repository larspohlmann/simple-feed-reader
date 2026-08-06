<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ProviderCredentials;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StubModelCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The provider is never really called: the catalog is replaced in the
 * container, so these cases prove the endpoints' own behaviour without a
 * network. Tokens are minted from the JWT manager so the login throttler's
 * filesystem pool stays out of it.
 */
final class AiSettingsControllerTest extends ApiTestCase
{
    private const string BASE_URL = 'https://api.example.test/v1';
    private const string API_KEY = 'sk-abcdef1234';
    private const string REFUSED_KEY = 'sk-refused9876';
    /** Must match framework.rate_limiter.ai_provider.limit in rate_limiter.yaml. */
    private const int PROVIDER_BUDGET = 30;

    protected function setUp(): void
    {
        // The ai_provider limiter counts in a FILESYSTEM pool that outlives the
        // run, and every case here authenticates as user id 1 once the
        // transaction rolls back — so a prior case's spend would trip a 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /**
     * @param list<string>|\Throwable|\Closure(ProviderCredentials): list<string> $models
     */
    private function clientAnswering(array|\Throwable|\Closure $models): KernelBrowser
    {
        $client = static::createClient();
        // KernelBrowser rebuilds the container after every request, which would
        // discard the stub before the second call of every multi-request case.
        $client->disableReboot();
        self::getContainer()->set(ModelCatalog::class, new StubModelCatalog($models));

        return $client;
    }

    private function authenticate(KernelBrowser $client, string $email): void
    {
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $manager->create($user));
    }

    private function accountOn(KernelBrowser $client, string $email): void
    {
        $this->factory()->create($email);
        $this->authenticate($client, $email);
    }

    private function putJson(KernelBrowser $client, string $uri, string $json): void
    {
        $client->request('PUT', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json);
    }

    private function saveConnection(KernelBrowser $client, string $apiKey = self::API_KEY): void
    {
        $this->putJson(
            $client,
            '/api/me/ai/connection',
            sprintf('{"baseUrl":"%s","apiKey":"%s"}', self::BASE_URL, $apiKey),
        );
    }

    private function body(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    public function testAnUnconfiguredAccountReportsNothingConfigured(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-empty@example.test');

        $client->request('GET', '/api/me/ai');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertFalse($payload['configured']);
        self::assertFalse($payload['ready']);
        self::assertNull($payload['baseUrl']);
    }

    public function testTheEndpointRefusesAnAnonymousCaller(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);

        $client->request('GET', '/api/me/ai');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSavingAConnectionReturnsTheModels(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-save@example.test');

        $this->saveConnection($client);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertTrue($payload['configured']);
        self::assertFalse($payload['ready']);
        self::assertSame('1234', $payload['apiKeyHint']);
        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $payload['models']);
    }

    /**
     * Sweeps every route, on the success path and on both refusal paths, and
     * demands the stored secret appears in none of them.
     *
     * The sweep carries its own positive control. A body that contains the
     * key's last four characters proves the response really was assembled from
     * the saved row, so the negative assertion is a statement about a populated
     * document rather than about an empty or absent one — the failure mode that
     * makes "the secret is not in the response" true for the wrong reason.
     * Remove the `apiKeyHint` term from AiSettingsJson and this case fails on
     * the control; make any endpoint echo the key and it fails on the sweep.
     */
    public function testNoEndpointEverReturnsTheApiKey(): void
    {
        $client = $this->clientAnswering(
            static fn (ProviderCredentials $credentials): array => self::REFUSED_KEY === $credentials->apiKey
                ? throw new CredentialsRejectedException('That key was refused.')
                : ['gpt-4o', 'gpt-4o-mini'],
        );
        $this->accountOn($client, 'ai-secret@example.test');

        $this->saveConnection($client);
        $controls = [$this->assertBodyHasNoKey($client)];

        $client->request('GET', '/api/me/ai');
        $controls[] = $this->assertBodyHasNoKey($client);

        $client->request('GET', '/api/me/ai/models');
        $this->assertBodyHasNoKey($client);

        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o"}');
        $controls[] = $this->assertBodyHasNoKey($client);

        // /api/me is swept but is not a control: MeJson reports only `ready`
        // and `model`, so its body carries no hint to look for.
        $client->request('GET', '/api/me');
        $this->assertBodyHasNoKey($client);

        // The refusal paths: a problem document must not carry the secret
        // either, neither the one the account just sent nor the stored one.
        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-9"}');
        self::assertResponseStatusCodeSame(422);
        $this->assertBodyHasNoKey($client);

        $this->saveConnection($client, self::REFUSED_KEY);
        self::assertResponseStatusCodeSame(422);
        $this->assertBodyHasNoKey($client);
        self::assertStringNotContainsString(self::REFUSED_KEY, $this->body($client));

        self::assertNotContains(false, $controls, 'A response that should carry the key hint did not.');
    }

    /** @return bool whether this body carried the key hint — the sweep's positive control */
    private function assertBodyHasNoKey(KernelBrowser $client): bool
    {
        $body = $this->body($client);
        self::assertStringNotContainsString(self::API_KEY, $body);

        return str_contains($body, substr(self::API_KEY, -4));
    }

    /**
     * Both refusals, on every route that can raise them. Each is listed
     * separately in a union catch, and dropping one type from any of those
     * unions turns a routine failure — a mistyped address, a revoked key — into
     * the opaque 500 the listener produces for an unexpected throw.
     *
     * @return iterable<string, array{\Throwable}>
     */
    public static function providerRefusals(): iterable
    {
        yield 'an address that does not answer' => [
            new ProviderUnreachableException('That address did not answer.'),
        ];
        yield 'a key the provider refuses' => [
            new CredentialsRejectedException('That key was refused.'),
        ];
    }

    #[DataProvider('providerRefusals')]
    public function testAProviderRefusalOnTheConnectionWriteIsUnprocessable(\Throwable $refusal): void
    {
        $client = $this->clientAnswering($refusal);
        $this->accountOn($client, 'ai-refused@example.test');

        $this->saveConnection($client, 'sk-wrong-key');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client)['type']);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    #[DataProvider('providerRefusals')]
    public function testAProviderRefusalWhileListingModelsIsUnprocessable(\Throwable $refusal): void
    {
        $client = $this->clientTurningBad($refusal);
        $this->accountOn($client, 'ai-relist-bad@example.test');

        $this->saveConnection($client);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/me/ai/models');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client)['type']);
    }

    #[DataProvider('providerRefusals')]
    public function testAProviderRefusalOnTheModelWriteIsUnprocessable(\Throwable $refusal): void
    {
        $client = $this->clientTurningBad($refusal);
        $this->accountOn($client, 'ai-model-bad@example.test');

        $this->saveConnection($client);
        self::assertResponseIsSuccessful();

        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o"}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client)['type']);
    }

    /**
     * A provider that answers the save and has gone bad by the next call — the
     * shape every case needs that must store a connection before it can prove
     * how a later refusal is reported.
     */
    private function clientTurningBad(\Throwable $refusal): KernelBrowser
    {
        $answered = false;

        return $this->clientAnswering(function () use (&$answered, $refusal): array {
            if ($answered) {
                throw $refusal;
            }
            $answered = true;

            return ['gpt-4o'];
        });
    }

    public function testChoosingAModelMakesTheAccountReady(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-ready@example.test');

        $this->saveConnection($client);
        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o-mini"}');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['ready']);

        $client->request('GET', '/api/me');
        $payload = $this->payload($client);
        self::assertIsArray($payload['ai']);
        self::assertTrue($payload['ai']['ready']);
        self::assertSame('gpt-4o-mini', $payload['ai']['model']);
    }

    public function testAModelTheProviderDoesNotOfferIsRefused(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-badmodel@example.test');

        $this->saveConnection($client);
        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-9"}');

        self::assertResponseStatusCodeSame(422);
    }

    public function testTheModelListCanBeReReadWithTheStoredKey(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-relist@example.test');

        $this->saveConnection($client);
        $client->request('GET', '/api/me/ai/models');

        self::assertResponseIsSuccessful();
        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $this->payload($client)['models']);
    }

    public function testListingModelsWithoutAConfigurationIsNotFound(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-nolist@example.test');

        $client->request('GET', '/api/me/ai/models');

        self::assertResponseStatusCodeSame(404);
    }

    public function testChoosingAModelWithoutAConfigurationIsNotFound(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-nomodel@example.test');

        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o"}');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnEmptyConnectionBodyIsRejectedBeforeAnyProviderCall(): void
    {
        $client = $this->clientAnswering(
            new \LogicException('The provider must not be called for an invalid body.'),
        );
        $this->accountOn($client, 'ai-blank@example.test');

        $this->putJson($client, '/api/me/ai/connection', '{"baseUrl":"","apiKey":""}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }

    /**
     * A stored key that no longer decrypts — a rotated AI_KEY_SECRET, an edited
     * row, a row moved between accounts. Without the mapping this is an
     * uncaught throw, so the account gets an opaque 500 on every read and no
     * hint that entering the key again is the way out.
     *
     * The row is corrupted through raw SQL rather than through the entity: the
     * ciphertext columns are private and the cipher is the only writer, which
     * is exactly the invariant this case has to break.
     */
    public function testAnUnreadableStoredKeyIsReportedAsUnprocessable(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-unreadable@example.test');

        $this->saveConnection($client);
        self::assertResponseIsSuccessful();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            "UPDATE user_ai_settings SET api_key_ciphertext = 'not-a-sealed-key'",
        );
        $entityManager->clear();

        $client->request('GET', '/api/me/ai/models');

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($client);
        self::assertSame('ai_provider_rejected', $payload['type']);
        self::assertSame('The stored API key can no longer be read. Enter it again.', $payload['detail']);
        // The cipher's own diagnosis describes the stored material; it stays in
        // the log, not in the document.
        self::assertStringNotContainsString('integrity', $this->body($client));

        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o"}');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client)['type']);
    }

    /**
     * Pins that the budget is actually spent. Every other case proves only that
     * the limiter does NOT fire, so a limiter argument bound to the wrong
     * service — it autowires by parameter name — would leave the whole suite
     * green with the endpoints uncapped.
     *
     * The budget is spent through the endpoints themselves rather than by
     * consuming from the factory in the test, so what is pinned is the limiter
     * the controller holds, not one the test picked out of the container.
     *
     * One save, then the rest of the budget spent on the model list — so the
     * three routes that share the budget each both pay into it and refuse once
     * it is gone. Removing the guard from any single one of them leaves the
     * budget unspent, and its 429 becomes a 200.
     */
    public function testTheProviderBudgetIsSpentAndRunsOut(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-budget@example.test');

        $this->saveConnection($client);
        self::assertResponseIsSuccessful();

        for ($spent = 1; $spent < self::PROVIDER_BUDGET; ++$spent) {
            $client->request('GET', '/api/me/ai/models');
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent + 1));
        }

        $client->request('GET', '/api/me/ai/models');
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload($client)['type']);
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));

        $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o"}');
        self::assertResponseStatusCodeSame(429);

        $this->saveConnection($client);
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * The other side of the same budget: a request that cannot make an outbound
     * call must not pay for one. More refusals than the budget holds, and every
     * one of them still answers 404 rather than turning into a 429.
     */
    public function testAnUnconfiguredAccountDoesNotSpendTheProviderBudget(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-nobudget@example.test');

        for ($attempt = 0; $attempt <= self::PROVIDER_BUDGET; ++$attempt) {
            $client->request('GET', '/api/me/ai/models');
            self::assertResponseStatusCodeSame(404);

            $this->putJson($client, '/api/me/ai/model', '{"model":"gpt-4o"}');
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testDeletingAnUnconfiguredAccountStillSucceeds(): void
    {
        // A delete is idempotent by contract: a client that repeats one, or
        // clears an account that was never configured, must not have to read a
        // successful no-op as an error.
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-forget-twice@example.test');

        $client->request('DELETE', '/api/me/ai');
        self::assertResponseStatusCodeSame(204);

        $client->request('DELETE', '/api/me/ai');
        self::assertResponseStatusCodeSame(204);
    }

    public function testDeletingTheConfigurationClearsIt(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-forget@example.test');

        $this->saveConnection($client);
        $client->request('DELETE', '/api/me/ai');

        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/me/ai');
        self::assertFalse($this->payload($client)['configured']);
    }

    public function testOneAccountCannotSeeAnothersConfiguration(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-owner@example.test');
        $this->factory()->create('ai-stranger@example.test');

        $this->saveConnection($client);

        $this->authenticate($client, 'ai-stranger@example.test');
        $client->request('GET', '/api/me/ai');

        self::assertFalse($this->payload($client)['configured']);
    }
}
