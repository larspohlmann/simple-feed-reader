<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `/api/admin/proxy` is covered by the existing `^/api/admin/` ROLE_ADMIN
 * prefix rule in security.yaml — no new access_control entry needed, confirmed
 * by reading it before writing this test (see AdminSettingsControllerTest).
 */
final class AdminProxyControllerTest extends WebTestCase
{
    private const string PROXY = '/api/admin/proxy';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    private function factory(): UserFactory
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return new UserFactory($em, $hasher);
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

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'type' => 'SOCKS5',
            'host' => 'proxy.example',
            'port' => 1080,
            'username' => 'user',
            'password' => 'sw0rdfish',
        ]);

        self::assertResponseIsSuccessful();

        $this->client->request(
            'GET',
            self::PROXY,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload();
        self::assertTrue($body['hasPassword']);
        self::assertSame('fish', $body['passwordHint']);
        self::assertArrayNotHasKey('password', $body);
    }

    public function testPuttingWithoutAPasswordKeepsTheStoredSecret(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'type' => 'SOCKS5',
            'host' => 'proxy.example',
            'port' => 1080,
            'username' => 'user',
            'password' => 'sw0rdfish',
        ]);
        self::assertResponseIsSuccessful();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'type' => 'SOCKS5',
            'host' => 'proxy.example',
            'port' => 1080,
            'username' => 'user',
            'password' => null,
        ]);
        self::assertResponseIsSuccessful();

        $body = $this->payload();
        self::assertTrue($body['hasPassword']);
        self::assertSame('fish', $body['passwordHint']);
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
        $body = $this->payload();
        self::assertFalse($body['ok']);
    }
}
