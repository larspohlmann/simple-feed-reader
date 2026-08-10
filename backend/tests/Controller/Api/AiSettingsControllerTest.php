<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\ModelCatalog;
use App\Service\Ai\ProviderCredentials;
use App\Tests\Support\AiProviderSettingsFactory;
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

    private function postJson(KernelBrowser $client, string $uri, string $json): void
    {
        $client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $json);
    }

    /** @return array<string, mixed> the decoded body of the add response */
    private function addConfiguration(
        KernelBrowser $client,
        string $apiKey = self::API_KEY,
        ?string $name = null,
    ): array {
        $this->postJson(
            $client,
            '/api/me/ai/configs',
            sprintf('{"name":%s,"baseUrl":"%s","apiKey":"%s"}', json_encode($name), self::BASE_URL, $apiKey),
        );

        return $this->payload($client);
    }

    private function chooseModel(KernelBrowser $client, int $id, string $model): void
    {
        $this->putJson($client, sprintf('/api/me/ai/configs/%d/model', $id), sprintf('{"model":"%s"}', $model));
    }

    /** Adds a configuration and chooses a model on it in one call — most cases need only that. */
    private function addAndReadyConfiguration(KernelBrowser $client, string $model = 'gpt-4o'): int
    {
        $added = $this->addConfiguration($client);
        $id = $added['id'];
        self::assertIsInt($id);

        $this->chooseModel($client, $id, $model);
        self::assertResponseIsSuccessful();

        return $id;
    }

    private function body(KernelBrowser $client): string
    {
        return (string) $client->getResponse()->getContent();
    }

    public function testAnUnconfiguredAccountReportsAnEmptyList(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-empty@example.test');

        $client->request('GET', '/api/me/ai');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame([], $payload['configs']);
        self::assertNull($payload['activeId']);
    }

    public function testTheListEndpointRefusesAnAnonymousCaller(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);

        $client->request('GET', '/api/me/ai');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAddingAConfigurationReturnsItWithTheOfferedModels(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-add@example.test');

        $added = $this->addConfiguration($client, name: 'Work OpenAI');

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Work OpenAI', $added['name']);
        self::assertSame('1234', $added['apiKeyHint']);
        self::assertFalse($added['ready']);
        self::assertFalse($added['active']);
        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $added['models']);
    }

    public function testChoosingAModelOnTheOnlyConfigurationMakesItActive(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-ready@example.test');

        $id = $this->addAndReadyConfiguration($client, 'gpt-4o-mini');

        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertIsArray($payload['configs']);
        self::assertCount(1, $payload['configs']);
        self::assertSame($id, $payload['activeId']);
        self::assertIsArray($payload['configs'][0]);
        self::assertTrue($payload['configs'][0]['active']);
        self::assertTrue($payload['configs'][0]['ready']);

        $client->request('GET', '/api/me');
        $me = $this->payload($client);
        self::assertIsArray($me['ai']);
        self::assertTrue($me['ai']['ready']);
        self::assertSame('gpt-4o-mini', $me['ai']['model']);
    }

    public function testActivatingASecondConfigurationSwitchesTheActiveOne(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-switch@example.test');

        $first = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $second = $this->addAndReadyConfiguration($client, 'gpt-4o-mini');

        // Adding a second configuration and choosing its model does not steal
        // the pointer from the first — only an explicit activate does.
        $client->request('GET', '/api/me/ai');
        self::assertSame($first, $this->payload($client)['activeId']);

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/active', $second), '{}');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertSame($second, $payload['activeId']);

        $client->request('GET', '/api/me');
        $me = $this->payload($client);
        self::assertIsArray($me['ai']);
        self::assertSame('gpt-4o-mini', $me['ai']['model']);
    }

    public function testRenamingAConfigurationChangesItsName(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-rename@example.test');
        $added = $this->addConfiguration($client, name: 'Old name');
        $id = $added['id'];
        self::assertIsInt($id);

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/name', $id), '{"name":"New name"}');

        self::assertResponseIsSuccessful();
        self::assertSame('New name', $this->payload($client)['name']);

        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertIsArray($payload['configs']);
        self::assertIsArray($payload['configs'][0]);
        self::assertSame('New name', $payload['configs'][0]['name']);
    }

    public function testSettingReasoningChangesTheConfiguration(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-reasoning@example.test');
        $added = $this->addConfiguration($client);
        $id = $added['id'];
        self::assertIsInt($id);
        self::assertTrue($added['suppressReasoning']); // default on

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/reasoning', $id), '{"suppressReasoning":false}');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($client)['suppressReasoning']);

        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertIsArray($payload['configs']);
        self::assertIsArray($payload['configs'][0]);
        self::assertFalse($payload['configs'][0]['suppressReasoning']);
    }

    public function testSettingBatchConcurrencyChangesTheConfiguration(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-batch-concurrency@example.test');
        $added = $this->addConfiguration($client);
        $id = $added['id'];
        self::assertIsInt($id);
        self::assertSame(1, $added['batchConcurrency']); // default

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/batch-concurrency', $id), '{"batchConcurrency":3}');

        self::assertResponseIsSuccessful();
        self::assertSame(3, $this->payload($client)['batchConcurrency']);

        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertIsArray($payload['configs']);
        self::assertIsArray($payload['configs'][0]);
        self::assertSame(3, $payload['configs'][0]['batchConcurrency']);
    }

    public function testSettingBatchConcurrencyRejectsOutOfRange(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-batch-concurrency-range@example.test');
        $id = $this->addConfiguration($client)['id'];
        self::assertIsInt($id);

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/batch-concurrency', $id), '{"batchConcurrency":5}');

        self::assertResponseStatusCodeSame(422);
    }

    public function testDeletingANonActiveConfigurationDropsItFromTheList(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-delete-inactive@example.test');
        $active = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $extra = $this->addConfiguration($client)['id'];
        self::assertIsInt($extra);

        $client->request('DELETE', sprintf('/api/me/ai/configs/%d', $extra));

        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertIsArray($payload['configs']);
        self::assertCount(1, $payload['configs']);
        self::assertSame($active, $payload['activeId']);
    }

    public function testDeletingTheActiveConfigurationLeavesNoActiveId(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-delete-active@example.test');
        $active = $this->addAndReadyConfiguration($client);

        $client->request('DELETE', sprintf('/api/me/ai/configs/%d', $active));

        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/api/me/ai');
        $payload = $this->payload($client);
        self::assertSame([], $payload['configs']);
        self::assertNull($payload['activeId']);
    }

    /**
     * Every {id} route sweeps into ownership-scoped 404, never 403 — the
     * hard requirement that a caller cannot learn a stranger's id exists.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function idBearingRoutes(): iterable
    {
        yield 'listing models' => ['GET', '/models', '{}'];
        yield 'choosing a model' => ['PUT', '/model', '{"model":"gpt-4o"}'];
        yield 'renaming' => ['PUT', '/name', '{"name":"Stolen"}'];
        yield 'activating' => ['PUT', '/active', '{}'];
        yield 'setting reasoning' => ['PUT', '/reasoning', '{"suppressReasoning":false}'];
        yield 'setting batch concurrency' => ['PUT', '/batch-concurrency', '{"batchConcurrency":2}'];
        yield 'deleting' => ['DELETE', '', '{}'];
    }

    #[DataProvider('idBearingRoutes')]
    public function testAnIdBearingRouteRefusesAnotherAccountsConfiguration(
        string $method,
        string $suffix,
        string $body,
    ): void {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-stranger-victim@example.test');
        $strangerId = $this->addAndReadyConfiguration($client);

        $this->accountOn($client, 'ai-stranger-attacker@example.test');
        $uri = sprintf('/api/me/ai/configs/%d%s', $strangerId, $suffix);

        if ('GET' === $method) {
            $client->request('GET', $uri);
        } elseif ('DELETE' === $method) {
            $client->request('DELETE', $uri);
        } else {
            $this->putJson($client, $uri, $body);
        }

        self::assertResponseStatusCodeSame(404);
        self::assertSame('ai_configuration_not_found', $this->payload($client)['type']);
    }

    public function testAddingBeyondTheCapIsRefused(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-cap@example.test');
        $this->persistConfigurationsUpToTheCap('ai-cap@example.test');

        $this->addConfiguration($client);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('ai_configuration_limit', $this->payload($client)['type']);
    }

    /**
     * Persists 20 rows directly rather than through 20 HTTP calls: this case
     * proves the cap itself, not the endpoints that would otherwise dominate
     * the run time and the provider-budget bookkeeping for no extra coverage.
     */
    private function persistConfigurationsUpToTheCap(string $email): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);

        for ($i = 0; $i < 20; ++$i) {
            $configuration = AiProviderSettingsFactory::build(
                $user,
                baseUrl: self::BASE_URL,
                verifiedAt: new \DateTimeImmutable(),
            );
            $entityManager->persist($configuration);
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    public function testActivatingAConfigurationWithoutAModelIsRefused(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-activate-no-model@example.test');
        $id = $this->addConfiguration($client)['id'];
        self::assertIsInt($id);

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/active', $id), '{}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client)['type']);
    }

    /**
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
    public function testAProviderRefusalOnAddIsUnprocessable(\Throwable $refusal): void
    {
        $client = $this->clientAnswering($refusal);
        $this->accountOn($client, 'ai-refused@example.test');

        $this->addConfiguration($client, 'sk-wrong-key');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('ai_provider_rejected', $this->payload($client)['type']);
        self::assertStringContainsString(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }

    public function testAnEmptyAddBodyIsRejectedBeforeAnyProviderCall(): void
    {
        $client = $this->clientAnswering(
            new \LogicException('The provider must not be called for an invalid body.'),
        );
        $this->accountOn($client, 'ai-blank@example.test');

        $this->postJson($client, '/api/me/ai/configs', '{"name":null,"baseUrl":"","apiKey":""}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }

    /**
     * A stored key that no longer decrypts — a rotated AI_KEY_SECRET, an edited
     * row, a row moved between accounts. Without the mapping this is an
     * uncaught throw, so the account gets an opaque 500 on every read and no
     * hint that entering the key again is the way out.
     */
    public function testAnUnreadableStoredKeyIsReportedAsUnprocessable(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-unreadable@example.test');
        $id = $this->addConfiguration($client)['id'];
        self::assertIsInt($id);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement(
            "UPDATE user_ai_settings SET api_key_ciphertext = 'not-a-sealed-key'",
        );
        $entityManager->clear();

        $client->request('GET', sprintf('/api/me/ai/configs/%d/models', $id));

        self::assertResponseStatusCodeSame(422);
        $payload = $this->payload($client);
        self::assertSame('ai_key_unreadable', $payload['type']);
        self::assertSame('The stored API key can no longer be read. Enter it again.', $payload['detail']);
        self::assertStringNotContainsString('integrity', $this->body($client));
    }

    /**
     * Sweeps the write paths and demands the stored secret appears in none of
     * them. The sweep carries its own positive control: a body containing the
     * key's last four characters proves the response really was assembled
     * from the saved row.
     */
    public function testNoEndpointEverReturnsTheApiKey(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-secret@example.test');

        $added = $this->addConfiguration($client);
        $id = $added['id'];
        self::assertIsInt($id);
        $controls = [$this->assertBodyHasNoKey($client)];

        $client->request('GET', '/api/me/ai');
        $controls[] = $this->assertBodyHasNoKey($client);

        $client->request('GET', sprintf('/api/me/ai/configs/%d/models', $id));
        $this->assertBodyHasNoKey($client);

        $this->chooseModel($client, $id, 'gpt-4o');
        $controls[] = $this->assertBodyHasNoKey($client);

        // /api/me is swept but is not a control: MeJson reports only `ready`
        // and `model`, so its body carries no hint to look for.
        $client->request('GET', '/api/me');
        $this->assertBodyHasNoKey($client);

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
     * Pins that the budget is actually spent. Every other case proves only
     * that the limiter does NOT fire, so a limiter argument bound to the
     * wrong service — it autowires by parameter name — would leave the whole
     * suite green with the endpoints uncapped.
     *
     * One add, then the rest of the budget spent on the model list — both
     * routes share the budget and both pay into it.
     */
    public function testTheProviderBudgetIsSpentAndRunsOut(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-budget@example.test');

        $id = $this->addConfiguration($client)['id'];
        self::assertIsInt($id);
        self::assertResponseStatusCodeSame(201);

        for ($spent = 1; $spent < self::PROVIDER_BUDGET; ++$spent) {
            $client->request('GET', sprintf('/api/me/ai/configs/%d/models', $id));
            self::assertResponseIsSuccessful(sprintf('Request %d was inside the budget.', $spent + 1));
        }

        $client->request('GET', sprintf('/api/me/ai/configs/%d/models', $id));
        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $this->payload($client)['type']);
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));

        $this->chooseModel($client, $id, 'gpt-4o');
        self::assertResponseStatusCodeSame(429);
    }

    /**
     * The other side of the same budget: a request that cannot make an
     * outbound call must not pay for one. More refusals than the budget
     * holds, and every one of them still answers 404 rather than turning
     * into a 429.
     */
    public function testAnOwnedConfigurationLookupFailureDoesNotSpendTheProviderBudget(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-nobudget@example.test');

        for ($attempt = 0; $attempt <= self::PROVIDER_BUDGET; ++$attempt) {
            $client->request('GET', '/api/me/ai/configs/999999/models');
            self::assertResponseStatusCodeSame(404);
        }
    }
}
