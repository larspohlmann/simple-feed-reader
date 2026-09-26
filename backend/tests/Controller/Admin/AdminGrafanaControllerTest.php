<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\GrafanaSettings;
use App\Entity\User;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->resetGrafanaSettings();
    }

    /**
     * The settings singleton outlives a single test: its row sits in the
     * per-process schema and, worse, its resolved snapshot sits in a cache pool
     * shared across the whole run (#1012). A writer test would leak an override
     * into whichever test runs next, so clear both and start unconfigured
     * (#1041).
     */
    private function resetGrafanaSettings(): void
    {
        $this->em()->createQuery('DELETE FROM ' . GrafanaSettings::class . ' g')->execute();

        /** @var GrafanaSettingsCache $cache */
        $cache = self::getContainer()->get(GrafanaSettingsCache::class);
        $cache->forget();
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

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function grafanaBody(array $changes = []): array
    {
        return [
            'lokiPushUrl' => null,
            'lokiUsername' => null,
            'grafanaUrl' => 'https://cloud.example/grafana',
            'pyroscopePushUrl' => null,
            'profilingEnabled' => false,
            ...$changes,
        ];
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

        $this->requestWithJsonBody('PUT', $admin, $this->grafanaBody(['token' => 'glc_secrettoken']));

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

        $this->requestWithJsonBody('PUT', $admin, $this->grafanaBody(['token' => 'glc_secrettoken']));
        self::assertResponseIsSuccessful();

        $this->requestWithJsonBody('PUT', $admin, $this->grafanaBody(['removeToken' => true]));

        self::assertResponseIsSuccessful();
        self::assertFalse($this->payload($this->client)['hasToken']);
    }

    public function testAdminCanRoundTripTheProfilingToggleAndPyroscopeUrl(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody(
            'PUT',
            $admin,
            $this->grafanaBody(['profilingEnabled' => true, 'pyroscopePushUrl' => 'http://custom:4040']),
        );
        self::assertResponseIsSuccessful();

        $this->client->request(
            'GET',
            self::GRAFANA,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertTrue($body['profilingEnabled']);
        self::assertSame('http://custom:4040', $body['pyroscopePushUrl']);
        self::assertIsBool($body['profilerAvailable']);
    }

    public function testAPutLeavingSettingsOutIsRefusedAndStoresNothing(): void
    {
        $admin = $this->admin();
        $this->requestWithJsonBody('PUT', $admin, $this->grafanaBody(['profilingEnabled' => true]));
        self::assertResponseIsSuccessful();

        $incomplete = $this->grafanaBody(['lokiUsername' => 'tenant7']);
        unset($incomplete['grafanaUrl'], $incomplete['profilingEnabled']);
        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['grafanaUrl', 'profilingEnabled'], array_keys($problem['errors']));

        $this->client->request(
            'GET',
            self::GRAFANA,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );
        $stored = $this->payload($this->client);
        self::assertSame('https://cloud.example/grafana', $stored['grafanaUrl']);
        self::assertNull($stored['lokiUsername']);
        self::assertTrue($stored['profilingEnabled']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function grafanaBodyKeys(): iterable
    {
        yield 'lokiPushUrl' => ['lokiPushUrl'];
        yield 'lokiUsername' => ['lokiUsername'];
        yield 'grafanaUrl' => ['grafanaUrl'];
        yield 'pyroscopePushUrl' => ['pyroscopePushUrl'];
        yield 'profilingEnabled' => ['profilingEnabled'];
    }

    #[DataProvider('grafanaBodyKeys')]
    public function testPutRefusesABodyMissingAnySingleSetting(string $key): void
    {
        $admin = $this->admin();
        $incomplete = $this->grafanaBody();
        unset($incomplete[$key]);

        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertIsArray($problem['errors']);
        self::assertSame([$key], array_keys($problem['errors']));
    }
}
