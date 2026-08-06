<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ProviderCredentials;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StubModelCatalog;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
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

    public function testARejectedKeyIsReportedAsUnprocessable(): void
    {
        $client = $this->clientAnswering(new CredentialsRejectedException('refused'));
        $this->accountOn($client, 'ai-refused@example.test');

        $this->saveConnection($client, 'sk-wrong-key');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
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
