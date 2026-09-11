<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `/api/admin/grafana` is covered by the existing `^/api/admin/` ROLE_ADMIN
 * prefix rule in security.yaml — no new access_control entry needed, confirmed
 * by reading it before writing this test (see AdminProxyControllerTest).
 */
final class AdminGrafanaControllerTest extends ApiTestCase
{
    private const string GRAFANA = '/api/admin/grafana';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager->create($user);
    }

    private function admin(string $email = 'boss@example.com'): User
    {
        return $this->factory()->create($email, roles: ['ROLE_ADMIN']);
    }

    /** @param array<string, mixed> $body */
    private function requestWithJsonBody(string $method, User $user, array $body): void
    {
        $this->client->request(
            $method,
            self::GRAFANA,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($user),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    public function testGetWithoutAdminTokenIsRejected(): void
    {
        $this->client->request('GET', self::GRAFANA);

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetAsNonAdminIsForbidden(): void
    {
        $plain = $this->factory()->create('plain@example.com');

        $this->client->request(
            'GET',
            self::GRAFANA,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($plain)],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetAsAdminReportsDefaultsAndNoOverrides(): void
    {
        $admin = $this->admin();

        $this->client->request(
            'GET',
            self::GRAFANA,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertNull($body['lokiPushUrl']);
        self::assertFalse($body['hasToken']);
        self::assertArrayHasKey('lokiPushUrlDefault', $body);
        self::assertArrayHasKey('lokiPushUrlEffective', $body);
        self::assertArrayHasKey('grafanaUrlDefault', $body);
        self::assertArrayHasKey('grafanaUrlEffective', $body);
    }

    public function testAdminCanRoundTripAnOverrideAndTokenWithoutLeakingTheSecret(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'grafanaUrl' => 'https://cloud.example/grafana',
            'token' => 'glc_secrettoken',
        ]);

        self::assertResponseIsSuccessful();

        $this->client->request(
            'GET',
            self::GRAFANA,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertSame('https://cloud.example/grafana', $body['grafanaUrl']);
        self::assertTrue($body['hasToken']);
        self::assertSame('oken', $body['tokenHint']);
        self::assertArrayNotHasKey('token', $body);
    }

    public function testRemoveTokenClearsTheStoredSecret(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'grafanaUrl' => 'https://cloud.example/grafana',
            'token' => 'glc_secrettoken',
        ]);
        self::assertResponseIsSuccessful();

        $this->requestWithJsonBody('PUT', $admin, [
            'grafanaUrl' => 'https://cloud.example/grafana',
            'removeToken' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($this->client)['hasToken']);
    }
}
