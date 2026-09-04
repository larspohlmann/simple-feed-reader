<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `/api/admin/mail` is covered by the existing `^/api/admin/` ROLE_ADMIN
 * prefix rule in security.yaml — no new access_control entry needed, confirmed
 * by reading it before writing this test (see AdminProxyControllerTest).
 */
final class AdminMailControllerTest extends ApiTestCase
{
    private const string MAIL = '/api/admin/mail';

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
            self::MAIL,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($user),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    public function testGetWithoutAdminTokenIsRejected(): void
    {
        $this->client->request('GET', self::MAIL);

        self::assertResponseStatusCodeSame(401);
    }

    public function testGetAsNonAdminIsForbidden(): void
    {
        $plain = $this->factory()->create('plain@example.com');

        $this->client->request(
            'GET',
            self::MAIL,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($plain)],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetOnAFreshDatabaseReportsNoPassword(): void
    {
        $admin = $this->admin();

        $this->client->request(
            'GET',
            self::MAIL,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['hasPassword']);
    }

    public function testAdminCanRoundTripMailSettingsWithoutLeakingTheSecret(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'host' => 'smtp.example',
            'port' => 587,
            'username' => 'user',
            'encryption' => 'starttls',
            'fromAddress' => 'noreply@example.com',
            'fromName' => 'Example',
            'password' => 'sw0rdfish',
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertTrue($body['hasPassword']);
        self::assertSame('fish', $body['passwordHint']);
        self::assertArrayNotHasKey('password', $body);
    }

    public function testTestConnectionReportsNotConfiguredWhenNothingIsSaved(): void
    {
        $admin = $this->admin();

        $this->client->request(
            'POST',
            self::MAIL . '/test',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['ok']);
        self::assertSame('not_configured', $body['reason']);
    }

    /**
     * The unit test (MailConnectionTesterTest) constructs the request DTO
     * directly, bypassing the validator and Security::getUser() — it cannot
     * prove the tester reaches a real transport for a logged-in admin. This
     * goes through the actual HTTP PUT and POST /test as an admin, with an
     * unreachable host, and checks the failure is a transport error, not the
     * "not_configured" short circuit.
     */
    public function testTestConnectionReachesTheTransportForAnUnreachableServer(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => 1,
            'fromAddress' => 'from@example.com',
            'password' => 'sw0rdfish',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->request(
            'POST',
            self::MAIL . '/test',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['ok']);
        self::assertNotSame('not_configured', $body['reason']);
    }

    public function testAdminCanResetToTheEnvironmentConfiguration(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'host' => 'smtp.example',
            'port' => 587,
            'username' => 'user',
            'encryption' => 'starttls',
            'fromAddress' => 'noreply@example.com',
            'fromName' => 'Example',
            'password' => 'sw0rdfish',
        ]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($this->client)['hasSavedConfig']);

        $this->client->request(
            'POST',
            self::MAIL . '/reset',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['hasSavedConfig']);
        self::assertArrayNotHasKey('password', $body);
    }

    public function testResetAsNonAdminIsForbidden(): void
    {
        $plain = $this->factory()->create('plain@example.com');

        $this->client->request(
            'POST',
            self::MAIL . '/reset',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($plain)],
        );

        self::assertResponseStatusCodeSame(403);
    }
}
