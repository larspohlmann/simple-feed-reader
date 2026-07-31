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
 * The admin's per-account limit controls: start/clear a trial, set/clear a
 * per-user subscription cap. Split from AdminUserControllerTest alongside its
 * controller — see AdminUserLimitsController for why.
 */
final class AdminUserLimitsControllerTest extends WebTestCase
{
    private const LIST = '/api/admin/users';

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
    private function requestWithJsonBody(string $method, string $uri, User $admin, array $body): void
    {
        $this->client->request(
            $method,
            $uri,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    public function testStartTrialSetsTrialAndReturnsIt(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('trial-target@example.com');

        $this->requestWithJsonBody('POST', self::LIST . '/' . $user->getId() . '/trial', $admin, ['days' => 14]);

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->payload()['status']);
        self::assertNotNull($this->payload()['trialEndsAt']);
    }

    public function testStartTrialRejectsNonPositiveDays(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('bad-days@example.com');

        $this->requestWithJsonBody('POST', self::LIST . '/' . $user->getId() . '/trial', $admin, ['days' => 0]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testStartTrialRejectsDaysBeyondTheUpperBound(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('too-many-days@example.com');

        $this->requestWithJsonBody('POST', self::LIST . '/' . $user->getId() . '/trial', $admin, ['days' => 3651]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testClearTrialMakesPermanent(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create(
            'perm@example.com',
            trialEndsAt: new \DateTimeImmutable('+10 days'),
        );

        $this->client->request(
            'DELETE',
            self::LIST . '/' . $user->getId() . '/trial',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['trialEndsAt']);
    }

    public function testSetSubscriptionLimitStoresTheOverride(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('cap-target@example.com');

        $this->requestWithJsonBody(
            'PUT',
            self::LIST . '/' . $user->getId() . '/subscription-limit',
            $admin,
            ['maxSubscriptions' => 42],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(42, $this->payload()['maxSubscriptions']);
    }

    public function testSetSubscriptionLimitRejectsNonPositiveValues(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('cap-zero@example.com');

        $this->requestWithJsonBody(
            'PUT',
            self::LIST . '/' . $user->getId() . '/subscription-limit',
            $admin,
            ['maxSubscriptions' => 0],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testSetSubscriptionLimitClearsWithNull(): void
    {
        $admin = $this->admin();
        $user = $this->factory()->create('cap-clear@example.com', maxSubscriptions: 5);

        $this->requestWithJsonBody(
            'PUT',
            self::LIST . '/' . $user->getId() . '/subscription-limit',
            $admin,
            ['maxSubscriptions' => null],
        );

        self::assertResponseIsSuccessful();
        self::assertNull($this->payload()['maxSubscriptions']);
    }
}
