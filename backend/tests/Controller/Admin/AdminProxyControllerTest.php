<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `/api/admin/proxy` is covered by the existing `^/api/admin/` ROLE_ADMIN
 * prefix rule in security.yaml — no new access_control entry needed, confirmed
 * by reading it before writing this test (see AdminSettingsControllerTest).
 */
final class AdminProxyControllerTest extends ApiTestCase
{
    private const string PROXY = '/api/admin/proxy';

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
            self::PROXY,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($user),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function proxyBody(array $changes = []): array
    {
        return [
            'enabled' => true,
            'directFallback' => true,
            'type' => 'SOCKS5',
            'host' => 'proxy.example',
            'port' => 1080,
            'username' => 'user',
            'remoteDns' => false,
            ...$changes,
        ];
    }

    public function testGetWithoutAdminTokenIsRejected(): void
    {
        $this->client->request('GET', self::PROXY);

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetAsNonAdminIsForbidden(): void
    {
        $plain = $this->factory()->create('plain@example.com');

        $this->client->request(
            'GET',
            self::PROXY,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($plain)],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanRoundTripProxySettingsWithoutLeakingTheSecret(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, $this->proxyBody(['password' => 'sw0rdfish']));

        self::assertResponseIsSuccessful();

        $this->client->request(
            'GET',
            self::PROXY,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertTrue($body['hasPassword']);
        self::assertArrayNotHasKey('passwordHint', $body);
        self::assertArrayNotHasKey('password', $body);
    }

    /**
     * The DNS switch is a full-replace field like the rest of the connection,
     * and the payload has to echo it back or the admin page cannot show it.
     */
    public function testRemoteDnsIsPersistedAndEchoedBack(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody(
            'PUT',
            $admin,
            $this->proxyBody(['remoteDns' => true, 'password' => 'sw0rdfish']),
        );

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($this->client)['remoteDns']);
    }

    public function testPuttingWithoutAPasswordKeepsTheStoredSecret(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, $this->proxyBody(['password' => 'sw0rdfish']));
        self::assertResponseIsSuccessful();

        $this->requestWithJsonBody('PUT', $admin, $this->proxyBody(['password' => null]));
        self::assertResponseIsSuccessful();

        $body = $this->payload($this->client);
        self::assertTrue($body['hasPassword']);
    }

    public function testUpdateRemovesTheStoredPasswordWhenRemovePasswordIsSet(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, $this->proxyBody(['password' => 'sw0rdfish']));
        self::assertResponseIsSuccessful();

        $this->requestWithJsonBody('PUT', $admin, $this->proxyBody(['removePassword' => true]));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($this->client)['hasPassword']);
    }

    public function testAPutLeavingSettingsOutIsRefusedAndStoresNothing(): void
    {
        $admin = $this->admin();
        $this->requestWithJsonBody('PUT', $admin, $this->proxyBody(['remoteDns' => true, 'password' => 'sw0rdfish']));
        self::assertResponseIsSuccessful();

        $incomplete = $this->proxyBody(['host' => 'other.example']);
        unset($incomplete['username'], $incomplete['remoteDns']);
        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['username', 'remoteDns'], array_keys($problem['errors']));

        $this->client->request(
            'GET',
            self::PROXY,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );
        $stored = $this->payload($this->client);
        self::assertSame('proxy.example', $stored['host']);
        self::assertTrue($stored['remoteDns']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function proxyBodyKeys(): iterable
    {
        yield 'enabled' => ['enabled'];
        yield 'directFallback' => ['directFallback'];
        yield 'type' => ['type'];
        yield 'host' => ['host'];
        yield 'port' => ['port'];
        yield 'username' => ['username'];
        yield 'remoteDns' => ['remoteDns'];
    }

    #[DataProvider('proxyBodyKeys')]
    public function testPutRefusesABodyMissingAnySingleSetting(string $key): void
    {
        $admin = $this->admin();
        $incomplete = $this->proxyBody();
        unset($incomplete[$key]);

        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertIsArray($problem['errors']);
        self::assertSame([$key], array_keys($problem['errors']));
    }

    public function testTestConnectionReportsNotConfiguredWhenNoProxyIsStored(): void
    {
        $admin = $this->admin();

        $this->client->request(
            'POST',
            self::PROXY . '/test',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['ok']);
    }
}
