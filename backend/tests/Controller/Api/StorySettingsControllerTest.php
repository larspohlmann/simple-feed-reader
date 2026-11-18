<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Service\Curation\Ai\Crypto\ApiKeyCipher;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @phpstan-type State array{
 *     writingConnectionId: ?int, writingConnection: ?array{id: int}, writingCandidates: list<array{id: int}>,
 *     titlesEnabled: bool
 * }
 */
final class StorySettingsControllerTest extends ApiTestCase
{
    private const string URI = '/api/me/ai/stories';

    public function testTheDefaultsShowTitlesOnAndTheActiveConnection(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->bearerAndUser('story-show@example.test');
        $active = $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');

        $client->request('GET', self::URI, server: $headers);

        self::assertResponseIsSuccessful();
        $state = $this->state($client);
        self::assertTrue($state['titlesEnabled']);
        self::assertNull($state['writingConnectionId']);
        self::assertSame($active->getId(), $state['writingConnection']['id'] ?? null);
        self::assertSame([$active->getId()], array_column($state['writingCandidates'], 'id'));
    }

    public function testSavingAPickAndTheSwitch(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->bearerAndUser('story-save@example.test');
        $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');
        $other = $this->fixtures()->seedInactiveAiSettingsFor($user, 'gpt-4o');

        $this->put($client, $headers, ['writingConnectionId' => $other->getId(), 'titlesEnabled' => false]);

        self::assertResponseIsSuccessful();
        $saved = $this->state($client);
        self::assertSame($other->getId(), $saved['writingConnectionId']);
        self::assertSame($other->getId(), $saved['writingConnection']['id'] ?? null);
        self::assertFalse($saved['titlesEnabled']);
        $client->request('GET', self::URI, server: $headers);
        self::assertSame($saved, $this->state($client));
    }

    public function testAScoringPickIsRejected(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->bearerAndUser('story-scoring@example.test');
        $scoring = $this->fixtures()->seedReadyScoringSettings($user);

        $this->put($client, $headers, ['writingConnectionId' => $scoring->getId(), 'titlesEnabled' => true]);

        $this->assertRejected($client, 422);
        self::assertSame('writing_connection_rejected', $this->payload($client)['type']);
    }

    public function testAnotherAccountsConnectionIsNotFound(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('story-owner@example.test'));
        $stranger = $this->factory()->create('story-stranger@example.test');
        $theirs = $this->fixtures()->seedReadyAiSettingsFor($stranger, 'gpt-4o');

        $this->put($client, $headers, ['writingConnectionId' => $theirs->getId(), 'titlesEnabled' => true]);

        $this->assertRejected($client, 404);
    }

    public function testClearingThePickFallsBackToTheActiveConnection(): void
    {
        $client = static::createClient();
        [$headers, $user] = $this->bearerAndUser('story-clear@example.test');
        $active = $this->fixtures()->seedReadyAiSettingsFor($user, 'qwen3-14b');
        $other = $this->fixtures()->seedInactiveAiSettingsFor($user, 'gpt-4o');
        $this->put($client, $headers, ['writingConnectionId' => $other->getId(), 'titlesEnabled' => true]);

        $this->put($client, $headers, ['writingConnectionId' => null, 'titlesEnabled' => true]);

        self::assertResponseIsSuccessful();
        $state = $this->state($client);
        self::assertNull($state['writingConnectionId']);
        self::assertNotNull($state['writingConnection']);
        self::assertSame($active->getId(), $state['writingConnection']['id']);
    }

    public function testANonPositiveConnectionIdIsAValidationError(): void
    {
        $client = static::createClient();
        $headers = $this->bearerFor($this->factory()->create('story-invalid@example.test'));

        $this->put($client, $headers, ['writingConnectionId' => 0, 'titlesEnabled' => true]);

        $this->assertRejected($client, 422);
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

    private function fixtures(): RecommendationRunFixtures
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);

        return new RecommendationRunFixtures($entityManager, $cipher);
    }
}
