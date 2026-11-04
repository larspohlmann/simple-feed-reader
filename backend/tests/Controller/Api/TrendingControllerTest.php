<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\TrendingSnapshot;
use App\Enum\TrendingWindow;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrendingFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class TrendingControllerTest extends ApiTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testListsTheReadersStoriesOfTheWindow(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-api@example.com');
        $fixtures = new TrendingFixtures($this->entityManager());
        $alpha = $fixtures->feed('alpha');
        $beta = $fixtures->feed('beta');
        $gamma = $fixtures->feed('gamma');
        $fixtures->subscribe($user, $alpha, $beta, $gamma);
        $lead = $fixtures->entry($alpha, 'Lead', '2031-05-14 10:00:00');
        $second = $fixtures->entry($beta, 'Second', '2031-05-14 09:00:00');
        $third = $fixtures->entry($gamma, 'Third', '2031-05-14 08:00:00');
        $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $lead, $second, $third);

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($this->client);
        self::assertSame('day', $payload['window']);
        self::assertNull($payload['builtAt']);
        self::assertIsArray($payload['stories']);
        self::assertCount(1, $payload['stories']);
        $story = $payload['stories'][0];
        self::assertIsArray($story);
        self::assertSame($lead->requireId(), $story['id']);
        self::assertSame('Lead', $story['title']);
        self::assertIsArray($story['duplicates']);
        self::assertSame(
            [$second->requireId(), $third->requireId()],
            array_map(
                static fn (mixed $outlet): mixed => \is_array($outlet) ? $outlet['id'] : null,
                $story['duplicates'],
            ),
        );
        self::assertSame(['outletCount' => 3, 'viewed' => false], $story['story']);
    }

    public function testBuiltAtIsSerialisedAsAtomInUtc(): void
    {
        [$headers] = $this->bearerAndUser('trending-built@example.com');
        $this->entityManager()->persist(new TrendingSnapshot(
            TrendingWindow::Week,
            new \DateTimeImmutable('2031-05-14 12:30:00'),
            10,
            0,
            5,
            1_000,
        ));
        $this->entityManager()->flush();

        $this->client->request('GET', '/api/trending?window=week', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('2031-05-14T12:30:00+00:00', $this->payload($this->client)['builtAt']);
    }

    public function testAMissingWindowMeansTheDay(): void
    {
        [$headers] = $this->bearerAndUser('trending-default@example.com');

        $this->client->request('GET', '/api/trending', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['window' => 'day', 'builtAt' => null, 'stories' => []], $this->payload($this->client));
    }

    public function testAnUnknownWindowIsAValidationProblem(): void
    {
        [$headers] = $this->bearerAndUser('trending-unknown@example.com');

        $this->client->request('GET', '/api/trending?window=month', server: $headers);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $errors = $this->payload($this->client)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey('window', $errors);
    }

    public function testRejectsAnonymous(): void
    {
        $this->client->request('GET', '/api/trending?window=day');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
