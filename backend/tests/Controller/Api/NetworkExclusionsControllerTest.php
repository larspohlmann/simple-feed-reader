<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class NetworkExclusionsControllerTest extends ApiTestCase
{
    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $user = $this->factory()->create('network-exclusions-api@example.com');
        $this->headers = $this->jsonBearerFor($user);
    }

    /** @return iterable<string, array{string}> */
    public static function surfaces(): iterable
    {
        yield 'All items' => ['all-items'];
        yield 'For you' => ['for-you'];
        yield 'Trending' => ['trending'];
    }

    #[DataProvider('surfaces')]
    public function testANetworkIsIncludedUntilExcludedAndTheListReportsIt(string $surface): void
    {
        self::assertSame([], $this->excludedNetworks($surface));

        $this->client->request('PUT', '/api/' . $surface . '/excluded-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['bluesky'], $this->excludedNetworks($surface));

        $this->client->request('DELETE', '/api/' . $surface . '/excluded-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([], $this->excludedNetworks($surface));
    }

    public function testEverySurfaceSwitchesIndependently(): void
    {
        $this->client->request('PUT', '/api/all-items/excluded-networks/mastodon', server: $this->headers);
        $this->client->request('PUT', '/api/for-you/excluded-networks/bluesky', server: $this->headers);
        $this->client->request('PUT', '/api/trending/excluded-networks/bluesky', server: $this->headers);
        $this->client->request('PUT', '/api/trending/excluded-networks/mastodon', server: $this->headers);

        self::assertSame(['mastodon'], $this->excludedNetworks('all-items'));
        self::assertSame(['bluesky'], $this->excludedNetworks('for-you'));
        self::assertSame(['bluesky', 'mastodon'], $this->excludedNetworks('trending'));
    }

    #[DataProvider('surfaces')]
    public function testAnUnknownNetworkIsNotFound(string $surface): void
    {
        $this->client->request('PUT', '/api/' . $surface . '/excluded-networks/myspace', server: $this->headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnUnknownSurfaceIsNotFound(): void
    {
        $this->client->request('GET', '/api/saved-searches/excluded-networks', server: $this->headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[DataProvider('surfaces')]
    public function testTheListNeedsALogin(string $surface): void
    {
        $this->client->request('GET', '/api/' . $surface . '/excluded-networks');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return list<string> */
    private function excludedNetworks(string $surface): array
    {
        $this->client->request('GET', '/api/' . $surface . '/excluded-networks', server: $this->headers);
        self::assertResponseIsSuccessful();

        /** @var list<string> $networks */
        $networks = $this->payload($this->client)['excludedNetworks'];

        return $networks;
    }
}
