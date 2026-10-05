<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ModelDescriptor;
use App\Entity\User;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\ModelCatalogInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Requests against /api/me/ai for an ApiTestCase. The catalog is replaced in the container, so no provider is ever
 * called; tokens come from the JWT manager, which keeps the login throttler's filesystem pool out of these cases.
 */
trait AiConfigurationRequests
{
    private const string BASE_URL = 'https://api.example.test/v1';
    private const string API_KEY = 'sk-abcdef1234';

    /**
     * The ai_provider limiter counts in a FILESYSTEM pool that outlives the run, and every case authenticates as user
     * id 1 once the transaction rolls back, so a prior case's spend would trip a 429.
     */
    private function resetTheProviderBudget(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /**
     * @param list<string|ModelDescriptor>|\Throwable
     *     |\Closure(ProviderCredentialsModel): list<string|ModelDescriptor> $models
     */
    private function clientAnswering(array|\Throwable|\Closure $models): KernelBrowser
    {
        $client = static::createClient();
        // KernelBrowser rebuilds the container after every request, which would
        // discard the stub before the second call of every multi-request case.
        $client->disableReboot();
        self::getContainer()->set(ModelCatalogInterface::class, new StubModelCatalog($models));

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

    /** @return list<array<string, mixed>> */
    private function configs(KernelBrowser $client): array
    {
        $configs = $this->payload($client)['configs'];
        self::assertIsArray($configs);

        /** @var list<array<string, mixed>> $configs */
        return $configs;
    }
}
