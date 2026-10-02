<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\AiConfigurationRequests;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AiProfileConnectionControllerTest extends ApiTestCase
{
    use AiConfigurationRequests;

    protected function setUp(): void
    {
        $this->resetTheProviderBudget();
    }

    public function testAJevConnectionBorrowsTheConnectionItIsGiven(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $jev, $llm);

        self::assertResponseIsSuccessful();
        self::assertSame($jev, $this->payload($client)['id']);
        self::assertSame($llm, $this->payload($client)['profileConnectionId']);
    }

    public function testEachJevConnectionKeepsItsOwnProfileConnection(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-two@example.test');
        $first = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $second = $this->addAndReadyConfiguration($client, 'gpt-4o-mini');
        $firstJev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $secondJev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $firstJev, $first);
        $this->chooseProfileConnection($client, $secondJev, $second);

        self::assertSame(
            [$first => null, $second => null, $firstJev => $first, $secondJev => $second],
            $this->profileConnections($client),
        );
    }

    public function testAJevConnectionCannotBuildTheProfile(): void
    {
        $client = $this->clientAnswering(['jev-latest']);
        $this->accountOn($client, 'ai-profile-jev@example.test');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $otherJev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $jev, $otherJev);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('profile_connection_rejected', $this->payload($client)['type']);
    }

    public function testAConnectionThatBuildsItsOwnProfileBorrowsNone(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-profile-own@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $other = $this->addAndReadyConfiguration($client, 'gpt-4o-mini');

        $this->chooseProfileConnection($client, $llm, $other);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('profile_not_borrowed', $this->payload($client)['type']);
    }

    public function testABodyWithoutAConnectionIsRefused(): void
    {
        $client = $this->clientAnswering(['jev-latest']);
        $this->accountOn($client, 'ai-profile-body@example.test');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/profile', $jev), '{}');

        self::assertResponseStatusCodeSame(422);
    }

    public function testClearingTheProfileConnectionAnswersNoContentEveryTime(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-clear@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $this->chooseProfileConnection($client, $jev, $llm);

        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $jev));
        self::assertResponseStatusCodeSame(204);
        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $jev));
        self::assertResponseStatusCodeSame(204);

        self::assertSame([$llm => null, $jev => null], $this->profileConnections($client));
    }

    public function testDeletingTheProfileConnectionLeavesTheJevConnectionWithoutOne(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-delete@example.test');
        $llm = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');
        $this->chooseProfileConnection($client, $jev, $llm);

        $client->request('DELETE', sprintf('/api/me/ai/configs/%d', $llm));

        self::assertResponseStatusCodeSame(204);
        self::assertSame([$jev => null], $this->profileConnections($client));
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-owner@example.test');
        $theirs = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $this->accountOn($client, 'ai-profile-stranger@example.test');
        $mine = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->chooseProfileConnection($client, $mine, $theirs);
        self::assertResponseStatusCodeSame(404);
        $this->chooseProfileConnection($client, $theirs, $mine);
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $theirs));
        self::assertResponseStatusCodeSame(404);

        self::assertSame([$mine => null], $this->profileConnections($client));
    }

    private function chooseProfileConnection(KernelBrowser $client, int $borrower, int $connection): void
    {
        $this->putJson(
            $client,
            sprintf('/api/me/ai/configs/%d/profile', $borrower),
            sprintf('{"connectionId":%d}', $connection),
        );
    }

    /** @return array<int|string, mixed> each configuration's profileConnectionId, keyed by its id */
    private function profileConnections(KernelBrowser $client): array
    {
        $client->request('GET', '/api/me/ai');

        return array_column($this->configs($client), 'profileConnectionId', 'id');
    }
}
