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
     * The settings row and its cached snapshot (a pool shared by the whole run) both outlive a test, so a writer
     * would leak its override into the next one: clear both and start unconfigured.
     */
    private function resetGrafanaSettings(): void
    {
        $this->entityManager()->createQuery('DELETE FROM ' . GrafanaSettings::class . ' g')->execute();

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

    public function testAPutLeavingSettingsOutIsRefusedAndStoresNothing(): void
    {
        $admin = $this->admin();
        $this->requestWithJsonBody('PUT', $admin, $this->grafanaBody(['lokiPushUrl' => 'https://loki.example/push']));
        self::assertResponseIsSuccessful();

        $incomplete = $this->grafanaBody(['lokiUsername' => 'tenant7']);
        unset($incomplete['lokiPushUrl'], $incomplete['grafanaUrl']);
        $this->requestWithJsonBody('PUT', $admin, $incomplete);

        self::assertResponseStatusCodeSame(422);
        $problem = $this->payload($this->client);
        self::assertSame('validation_error', $problem['type']);
        self::assertIsArray($problem['errors']);
        self::assertSame(['lokiPushUrl', 'grafanaUrl'], array_keys($problem['errors']));

        $this->client->request(
            'GET',
            self::GRAFANA,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );
        $stored = $this->payload($this->client);
        self::assertSame('https://cloud.example/grafana', $stored['grafanaUrl']);
        self::assertSame('https://loki.example/push', $stored['lokiPushUrl']);
        self::assertNull($stored['lokiUsername']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function grafanaBodyKeys(): iterable
    {
        yield 'lokiPushUrl' => ['lokiPushUrl'];
        yield 'lokiUsername' => ['lokiUsername'];
        yield 'grafanaUrl' => ['grafanaUrl'];
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
