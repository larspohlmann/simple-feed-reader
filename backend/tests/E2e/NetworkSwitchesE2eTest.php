<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;
use Symfony\Component\HttpFoundation\Response;

/** A network switches out of All items and back over the wire, leaving For you's switches alone. */
final class NetworkSwitchesE2eTest extends E2eTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@example.com';
    private const string ADMIN_PASSWORD = 'e2e-admin-password-123';

    public function testANetworkSwitchesOutOfAllItemsAndBackWithoutTouchingForYou(): void
    {
        $token = $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
        $forYouBefore = $this->excludedNetworks($token, 'for-you');
        self::assertNotContains('mastodon', $this->excludedNetworks($token, 'all-items'));

        try {
            self::assertSame(Response::HTTP_NO_CONTENT, $this->switchNetwork($token, 'PUT'));
            self::assertContains('mastodon', $this->excludedNetworks($token, 'all-items'));
            self::assertSame($forYouBefore, $this->excludedNetworks($token, 'for-you'));
        } finally {
            self::assertSame(Response::HTTP_NO_CONTENT, $this->switchNetwork($token, 'DELETE'));
        }

        self::assertNotContains('mastodon', $this->excludedNetworks($token, 'all-items'));
    }

    private function switchNetwork(string $token, string $method): int
    {
        return $this->http->request($method, '/api/all-items/excluded-networks/mastodon', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ])->getStatusCode();
    }

    /** @return list<string> */
    private function excludedNetworks(string $token, string $surface): array
    {
        $response = $this->getJson('/api/' . $surface . '/excluded-networks', $token);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        /** @var list<string> $networks */
        $networks = $response->toArray()['excludedNetworks'];

        return $networks;
    }
}
