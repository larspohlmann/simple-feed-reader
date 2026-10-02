<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai\ModelCatalog;

use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Ai\Model\ModelDescriptorModel;
use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Ai\ModelCatalog\SystemOneCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SystemOneCatalogTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function presentStatuses(): iterable
    {
        yield 'TypeSafe refuses the empty body' => [422];
        yield 'a gateway refuses the empty body' => [400];
        yield 'rate limited, so the route exists' => [429];
    }

    #[DataProvider('presentStatuses')]
    public function testAnEndpointThatRefusesTheEmptyRequestOffersTheLatestAliasAt32k(int $status): void
    {
        $models = $this->catalogAnswering(new MockResponse('{}', ['http_code' => $status]))
            ->listModels($this->credentials());

        self::assertEquals([new ModelDescriptorModel('jev-latest', 32_000)], $models);
    }

    /** @return iterable<string, array{int}> */
    public static function absentStatuses(): iterable
    {
        yield 'no such route (Ollama, OpenAI)' => [404];
        yield 'no such method' => [405];
        yield 'answers every path (LM Studio)' => [200];
        yield 'server error' => [500];
        yield 'gateway error' => [502];
    }

    #[DataProvider('absentStatuses')]
    public function testAnyOtherAnswerMeansNoSystemOneEndpoint(int $status): void
    {
        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That address offers no System One endpoint.');

        $this->catalogAnswering(new MockResponse('{}', ['http_code' => $status]))->listModels($this->credentials());
    }

    /** @return iterable<string, array{int}> */
    public static function refusedKeyStatuses(): iterable
    {
        yield 'unknown key' => [401];
        yield 'forbidden key' => [403];
    }

    #[DataProvider('refusedKeyStatuses')]
    public function testARefusedKeyIsACredentialsFailure(int $status): void
    {
        $this->expectException(CredentialsRejectedException::class);

        $this->catalogAnswering(new MockResponse('{}', ['http_code' => $status]))->listModels($this->credentials());
    }

    public function testTheProbePostsAnEmptyObjectWithTheConnectionsKey(): void
    {
        $response = new MockResponse('{}', ['http_code' => 422]);

        $this->catalogAnswering($response)->listModels($this->credentials());

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://openrouter.test/api/v1/systemone', $response->getRequestUrl());
        $options = $response->getRequestOptions();
        self::assertSame('{}', $options['body']);
        self::assertIsArray($options['headers']);
        self::assertContains('Authorization: Bearer sk-or', $options['headers']);
        self::assertSame(5.0, $options['timeout']);
        self::assertSame(5.0, $options['max_duration']);
    }

    public function testAnAddressThatDoesNotAnswerIsUnreachable(): void
    {
        $this->expectExceptionMessage('That address did not answer.');

        $this->catalogAnswering(new MockResponse('', ['error' => 'Could not resolve host']))
            ->listModels($this->credentials());
    }

    private function catalogAnswering(MockResponse $response): SystemOneCatalog
    {
        return new SystemOneCatalog(new MockHttpClient($response), 'SimpleFeedReader/1.0');
    }

    private function credentials(): ProviderCredentialsModel
    {
        return ProviderCredentialsModel::fromStoredConfiguration('https://openrouter.test/api/v1', 'sk-or');
    }
}
