<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AllItemsNetworksControllerTest extends ApiTestCase
{
    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $user = $this->factory()->create('all-items-networks-api@example.com');
        $this->headers = $this->jsonBearerFor($user);
    }

    public function testANetworkIsIncludedUntilExcludedAndTheListReportsIt(): void
    {
        self::assertSame([], $this->excludedNetworks('all-items'));

        $this->client->request('PUT', '/api/all-items/excluded-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['bluesky'], $this->excludedNetworks('all-items'));

        $this->client->request('DELETE', '/api/all-items/excluded-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([], $this->excludedNetworks('all-items'));
    }

    public function testAllItemsAndForYouSwitchIndependently(): void
    {
        $this->client->request('PUT', '/api/all-items/excluded-networks/mastodon', server: $this->headers);
        $this->client->request('PUT', '/api/for-you/excluded-networks/bluesky', server: $this->headers);

        self::assertSame(['mastodon'], $this->excludedNetworks('all-items'));
        self::assertSame(['bluesky'], $this->excludedNetworks('for-you'));
    }

    public function testAnUnknownNetworkIsNotFound(): void
    {
        $this->client->request('PUT', '/api/all-items/excluded-networks/myspace', server: $this->headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListNeedsALogin(): void
    {
        $this->client->request('GET', '/api/all-items/excluded-networks');

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
