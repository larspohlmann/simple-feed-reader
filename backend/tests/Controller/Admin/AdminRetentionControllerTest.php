<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AdminRetentionControllerTest extends ApiTestCase
{
    private const string RETENTION = '/api/admin/retention';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
    }

    private function admin(): User
    {
        return $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
    }

    private function get(User $user): void
    {
        $this->client->request(
            'GET',
            self::RETENTION,
            server: $this->bearerFor($user),
        );
    }

    /** @param array<string, mixed> $body */
    private function put(User $user, array $body): void
    {
        $this->client->request(
            'PUT',
            self::RETENTION,
            server: [
                ...$this->bearerFor($user),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    public function testGetWithoutATokenIsRejected(): void
    {
        $this->client->request('GET', self::RETENTION);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPutAsNonAdminIsForbidden(): void
    {
        $this->put($this->factory()->create('plain@example.com'), [
            'entryRetentionDays' => 30,
            'socialRetentionDays' => 2,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAFreshInstanceReportsTheDefaultWindows(): void
    {
        $this->get($this->admin());

        self::assertResponseIsSuccessful();
        self::assertSame(['entryRetentionDays' => 90, 'socialRetentionDays' => 7], $this->payload($this->client));
    }

    public function testAdminCanStoreBothWindows(): void
    {
        $admin = $this->admin();

        $this->put($admin, ['entryRetentionDays' => 30, 'socialRetentionDays' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(['entryRetentionDays' => 30, 'socialRetentionDays' => 2], $this->payload($this->client));

        $this->get($admin);
        self::assertSame(['entryRetentionDays' => 30, 'socialRetentionDays' => 2], $this->payload($this->client));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBodies(): iterable
    {
        yield 'zero entry days' => [['entryRetentionDays' => 0, 'socialRetentionDays' => 7], 'entryRetentionDays'];
        yield 'zero social days' => [['entryRetentionDays' => 90, 'socialRetentionDays' => 0], 'socialRetentionDays'];
        yield 'too many days' => [['entryRetentionDays' => 3651, 'socialRetentionDays' => 7], 'entryRetentionDays'];
        yield 'missing social days' => [['entryRetentionDays' => 90], 'socialRetentionDays'];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsRefusedAndStoresNothing(array $body, string $invalidField): void
    {
        $admin = $this->admin();

        $this->put($admin, $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = $this->payload($this->client);
        self::assertIsArray($problem['errors']);
        self::assertSame([$invalidField], array_keys($problem['errors']));

        $this->get($admin);
        self::assertSame(['entryRetentionDays' => 90, 'socialRetentionDays' => 7], $this->payload($this->client));
    }
}
