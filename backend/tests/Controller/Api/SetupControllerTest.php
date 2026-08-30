<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Repository\UserRepository;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetupControllerTest extends WebTestCase
{
    private const string SECRET = 'test-setup-secret-abcdef0123456789';

    /**
     * The per-IP `setup` limiter stores its state in a FILESYSTEM pool, which
     * survives the kernel reboot between requests *and* the end of the run —
     * see RegistrationTest for the same reset, needed for the same reason.
     *
     * Unlike RegistrationTest, each test method here boots its own client
     * (some need `enableSecret()` called first, so a shared setUp()-built
     * client would boot before that env override is in place). Booting here
     * only to read the cache pool, then shutting back down, clears the pool
     * without leaving a kernel behind that would make every test's own
     * `self::createClient()` throw "the kernel should only be booted once".
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
     * Restores the empty string .env ships, rather than unsetting the key
     * outright: %env(ADMIN_SETUP_SECRET)% is resolved at container-runtime on
     * every kernel boot (bootstrap.php only runs Dotenv once per process), so
     * an outright unset() leaves the variable genuinely undefined for every
     * later test in the whole PHPUnit run — not just this file — and turns
     * `status()`/`createAdmin()` into a 500 instead of the closed-endpoint 404.
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
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        (new UserFactory($em, $hasher))->create('existing@example.com', roles: ['ROLE_ADMIN']);
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
        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        $body = $this->body($client);
        self::assertIsBool($body['needsSetup']);
        self::assertIsBool($body['mailEnabled']);
        self::assertTrue($body['mailEnabled']);
    }

    /**
     * Public and anonymous on purpose (#624 follow-up): a visitor is allowed
     * to know whether this instance can complete a passkey sign-in, but never
     * anything about which accounts exist — see PasskeySignInAvailability's
     * own docblock for why this reads no credential or user row.
     */
    public function testStatusReportsPasskeySignInAvailableByDefault(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/setup/status');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->body($client)['passkeySignInAvailable']);
    }

    public function testStatusReportsPasskeySignInUnavailableWhenTheToggleIsOff(): void
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
            passkeySignInEnabled: false,
        ));

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
        self::assertArrayHasKey('token', $this->body($client));

        $users = $client->getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        self::assertTrue($users->hasAnyAdmin());
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
