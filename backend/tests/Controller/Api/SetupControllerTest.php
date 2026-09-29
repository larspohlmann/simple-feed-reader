<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\InstanceSettingsUpdate;
use App\EventListener\AddUserIdClaimOnTokenIssueListener;
use App\Repository\UserRepository;
use App\Service\Settings\InstanceSettings;
use App\Tests\Support\EnablesMailInTests;
use App\Tests\Support\TogglesPasskeySignIn;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetupControllerTest extends WebTestCase
{
    use EnablesMailInTests;
    use TogglesPasskeySignIn;

    private const string SECRET = 'test-setup-secret-abcdef0123456789';

    /**
     * The `setup` limiter's filesystem pool outlives every kernel, so it is cleared here. The kernel is shut down
     * again because some tests call enableSecret() before their own createClient() boots one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
        /** @var CacheItemPoolInterface $cache */
        $cache = self::getContainer()->get('test.cache.rate_limiter');
        $cache->clear();
        self::ensureKernelShutdown();
    }

    /**
     * Restores .env's empty string rather than unsetting: an unset ADMIN_SETUP_SECRET stays undefined for the rest
     * of the run and turns status() and createAdmin() into a 500 instead of the closed endpoint's 404.
     */
    protected function tearDown(): void
    {
        $_ENV['ADMIN_SETUP_SECRET'] = '';
        $_SERVER['ADMIN_SETUP_SECRET'] = '';
        putenv('ADMIN_SETUP_SECRET=');
        parent::tearDown();
    }

    private function enableSecret(): void
    {
        $_ENV['ADMIN_SETUP_SECRET'] = self::SECRET;
        $_SERVER['ADMIN_SETUP_SECRET'] = self::SECRET;
        putenv('ADMIN_SETUP_SECRET=' . self::SECRET);
    }

    private function seedAdmin(KernelBrowser $client): void
    {
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        (new UserFactory($entityManager, $hasher))->create('existing@example.com', roles: ['ROLE_ADMIN']);
    }

    /** @return array<string, mixed> */
    private function body(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function post(KernelBrowser $client, string $email, string $password, string $secret): void
    {
        $body = ['email' => $email, 'password' => $password, 'secret' => $secret];

        $client->request(
            'POST',
            '/api/setup/admin',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    public function testStatusReportsNeedsSetupOnEmptyInstance(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->body($client)['needsSetup']);
    }

    public function testStatusReportsFalseOnceAnAdminExists(): void
    {
        $client = self::createClient();
        $this->seedAdmin($client);

        $client->request('GET', '/api/setup/status');

        self::assertFalse($this->body($client)['needsSetup']);
    }

    public function testStatusReportsMailEnabled(): void
    {
        $client = self::createClient();
        $this->seedEnabledMailInstance();
        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        $body = $this->body($client);
        self::assertIsBool($body['needsSetup']);
        self::assertIsBool($body['mailEnabled']);
        self::assertTrue($body['mailEnabled']);
    }

    public function testStatusReportsPasskeySignInUnavailableByDefault(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->body($client)['passkeySignInAvailable']);
    }

    public function testStatusReportsPasskeySignInAvailableOnceEnabledWithAValidRelyingParty(): void
    {
        $client = self::createClient();
        $settings = $client->getContainer()->get(InstanceSettings::class);
        self::assertInstanceOf(InstanceSettings::class, $settings);
        $settings->update(new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
            passkeySignInEnabled: true,
        ));

        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->body($client)['passkeySignInAvailable']);
    }

    public function testStatusReportsPasskeySignInUnavailableWhenTheToggleIsOff(): void
    {
        $client = self::createClient();
        $this->disablePasskeySignIn();

        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        self::assertFalse($this->body($client)['passkeySignInAvailable']);
    }

    public function testCreatesAdminWithTheCorrectSecret(): void
    {
        $this->enableSecret();
        $client = self::createClient();

        $this->post($client, 'root@example.com', 'a-strong-password-123', self::SECRET);

        self::assertResponseStatusCodeSame(201);
        $token = $this->body($client)['token'];
        self::assertIsString($token);

        $users = $client->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertTrue($users->hasAnyAdmin());

        $admin = $users->findOneByEmail('root@example.com');
        self::assertNotNull($admin);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = $client->getContainer()->get(JWTTokenManagerInterface::class);
        self::assertSame($admin->getId(), $tokens->parse($token)[AddUserIdClaimOnTokenIssueListener::CLAIM] ?? null);
    }

    public function testWrongSecretIsForbidden(): void
    {
        $this->enableSecret();
        $client = self::createClient();

        $this->post($client, 'root@example.com', 'a-strong-password-123', 'wrong-secret');

        self::assertResponseStatusCodeSame(403);
    }

    public function testEndpointIs404WhenNoSecretConfigured(): void
    {
        $client = self::createClient();

        $this->post($client, 'root@example.com', 'a-strong-password-123', 'anything');

        self::assertResponseStatusCodeSame(404);
    }
}
