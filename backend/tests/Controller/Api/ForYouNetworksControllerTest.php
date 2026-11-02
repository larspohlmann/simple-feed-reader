<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ForYouNetworksControllerTest extends ApiTestCase
{
    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $user = $this->factory()->create('for-you-networks-api@example.com');
        $this->headers = $this->jsonBearerFor($user);
    }

    public function testANetworkIsIncludedUntilExcludedAndTheListReportsIt(): void
    {
        self::assertSame([], $this->excludedNetworks());

        $this->client->request('PUT', '/api/for-you/excluded-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['bluesky'], $this->excludedNetworks());

        $this->client->request('DELETE', '/api/for-you/excluded-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([], $this->excludedNetworks());
    }

    public function testAnUnknownNetworkIsNotFound(): void
    {
        $this->client->request('PUT', '/api/for-you/excluded-networks/myspace', server: $this->headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListNeedsALogin(): void
    {
        $this->client->request('GET', '/api/for-you/excluded-networks');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return list<string> */
    private function excludedNetworks(): array
    {
        $this->client->request('GET', '/api/for-you/excluded-networks', server: $this->headers);
        self::assertResponseIsSuccessful();

        /** @var list<string> $networks */
        $networks = $this->payload($this->client)['excludedNetworks'];

        return $networks;
    }
}
