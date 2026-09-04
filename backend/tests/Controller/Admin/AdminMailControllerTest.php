<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** `/api/admin/mail` is covered by the `^/api/admin/` ROLE_ADMIN prefix rule in security.yaml. */
final class AdminMailControllerTest extends ApiTestCase
{
    private const string MAIL = '/api/admin/mail';

    private const array SAVED_SMTP_ROW = [
        'enabled' => true,
        'host' => 'smtp.example',
        'port' => 587,
        'username' => 'user',
        'encryption' => 'starttls',
        'fromAddress' => 'noreply@example.com',
        'fromName' => 'Example',
        'password' => 'sw0rdfish',
    ];

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

    private function admin(): User
    {
        return $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
    }

    private function requestAs(User $user, string $method, string $path = self::MAIL): void
    {
        $this->client->request(
            $method,
            $path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($user)],
        );
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

        $this->requestAs($plain, 'GET');

        self::assertResponseStatusCodeSame(403);
    }

    public function testGetOnAFreshDatabaseReportsNoPassword(): void
    {
        $admin = $this->admin();

        $this->requestAs($admin, 'GET');

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['hasPassword']);
    }

    public function testAdminCanRoundTripMailSettingsWithoutLeakingTheSecret(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, self::SAVED_SMTP_ROW);

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertTrue($body['hasPassword']);
        self::assertSame('fish', $body['passwordHint']);
        self::assertArrayNotHasKey('password', $body);
    }

    public function testTestConnectionReportsNotConfiguredWhenNothingIsSaved(): void
    {
        $admin = $this->admin();

        $this->requestAs($admin, 'POST', self::MAIL . '/test');

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

        $this->requestAs($admin, 'POST', self::MAIL . '/test');

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['ok']);
        self::assertNotSame('not_configured', $body['reason']);
    }

    public function testAdminCanResetToTheEnvironmentConfiguration(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, self::SAVED_SMTP_ROW);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($this->client)['hasSavedConfig']);

        $this->requestAs($admin, 'POST', self::MAIL . '/reset');

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertFalse($body['hasSavedConfig']);
        self::assertArrayNotHasKey('password', $body);
    }

    public function testUpdateRejectsAHalfConfiguredAuthenticatedRowOnAFreshDatabase(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'host' => 'smtp.example',
            'username' => 'user',
            'password' => null,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));

        $this->requestAs($admin, 'GET');
        self::assertFalse($this->payload($this->client)['hasSavedConfig']);
    }

    public function testUpdateAcceptsKeepingAStoredPasswordOnAnAlreadyAuthedRow(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'host' => 'smtp.example',
            'username' => 'user',
            'password' => 'sw0rdfish',
        ]);
        self::assertResponseIsSuccessful();

        $this->requestWithJsonBody('PUT', $admin, [
            'enabled' => true,
            'host' => 'smtp.example',
            'username' => 'user',
            'password' => null,
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testResetAsNonAdminIsForbidden(): void
    {
        $plain = $this->factory()->create('plain@example.com');

        $this->requestAs($plain, 'POST', self::MAIL . '/reset');

        self::assertResponseStatusCodeSame(403);
    }
}
