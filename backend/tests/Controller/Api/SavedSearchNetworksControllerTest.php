<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SavedSearchNetworksControllerTest extends ApiTestCase
{
    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $user = $this->factory()->create('networks-api@example.com');
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $this->headers = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testANetworkIsExcludedUntilIncludedAndTheListReportsIt(): void
    {
        self::assertSame([], $this->includedNetworks());

        $this->client->request('PUT', '/api/saved-searches/included-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['bluesky'], $this->includedNetworks());

        $this->client->request('DELETE', '/api/saved-searches/included-networks/bluesky', server: $this->headers);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([], $this->includedNetworks());
    }

    public function testAnUnknownNetworkIsNotFound(): void
    {
        $this->client->request('PUT', '/api/saved-searches/included-networks/myspace', server: $this->headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return list<string> */
    private function includedNetworks(): array
    {
        $this->client->request('GET', '/api/saved-searches', server: $this->headers);

        /** @var list<string> $networks */
        $networks = $this->payload($this->client)['includedNetworks'];

        return $networks;
    }
}
