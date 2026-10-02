<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\AiConfigurationRequests;
use App\Tests\Support\ApiTestCase;

final class AiProfileConnectionControllerTest extends ApiTestCase
{
    use AiConfigurationRequests;

    protected function setUp(): void
    {
        $this->resetTheProviderBudget();
    }

    public function testChoosingAProfileConnectionMovesTheChoice(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'gpt-4o-mini']);
        $this->accountOn($client, 'ai-profile@example.test');
        $first = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $second = $this->addAndReadyConfiguration($client, 'gpt-4o-mini');

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/profile', $first), '{}');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($client)['profileSource']);
        $this->putJson($client, sprintf('/api/me/ai/configs/%d/profile', $second), '{}');

        $client->request('GET', '/api/me/ai');
        $flags = array_column($this->configs($client), 'profileSource', 'id');
        self::assertSame([$first => false, $second => true], $flags);
    }

    public function testAJevConnectionCannotBuildTheProfile(): void
    {
        $client = $this->clientAnswering(['gpt-4o', 'jev-latest']);
        $this->accountOn($client, 'ai-profile-jev@example.test');
        $jev = $this->addAndReadyConfiguration($client, 'jev-latest');

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/profile', $jev), '{}');

        self::assertResponseStatusCodeSame(422);
        self::assertSame('profile_connection_rejected', $this->payload($client)['type']);
    }

    public function testClearingTheProfileConnectionAnswersNoContentEveryTime(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-profile-clear@example.test');
        $id = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $this->putJson($client, sprintf('/api/me/ai/configs/%d/profile', $id), '{}');

        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $id));
        self::assertResponseStatusCodeSame(204);
        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $id));
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/me/ai');
        self::assertSame([$id => false], array_column($this->configs($client), 'profileSource', 'id'));
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $client = $this->clientAnswering(['gpt-4o']);
        $this->accountOn($client, 'ai-profile-owner@example.test');
        $theirs = $this->addAndReadyConfiguration($client, 'gpt-4o');
        $this->accountOn($client, 'ai-profile-stranger@example.test');

        $this->putJson($client, sprintf('/api/me/ai/configs/%d/profile', $theirs), '{}');
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', sprintf('/api/me/ai/configs/%d/profile', $theirs));
        self::assertResponseStatusCodeSame(404);
    }
}
