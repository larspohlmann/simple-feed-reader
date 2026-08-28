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
 * The two registration-gate toggles, admin-facing. `/api/admin/settings` is
 * covered by the existing `^/api/admin/` ROLE_ADMIN prefix rule in
 * security.yaml — no new access_control entry needed, confirmed by reading it
 * before writing this test.
 */
final class AdminSettingsControllerTest extends WebTestCase
{
    private const string SETTINGS = '/api/admin/settings';

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
            self::SETTINGS,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($user),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    public function testGetReturnsCurrentSettingsForAnAdmin(): void
    {
        $admin = $this->admin();

        $this->client->request(
            'GET',
            self::SETTINGS,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'requireEmailConfirmation' => true,
                'requireApproval' => true,
                'mailEnabled' => true,
                'publicBaseUrl' => null,
            ],
            $this->payload(),
        );
    }

    public function testPutUpdatesTheToggles(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody(
            'PUT',
            $admin,
            ['requireEmailConfirmation' => false, 'requireApproval' => true, 'publicBaseUrl' => null],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'requireEmailConfirmation' => false,
                'requireApproval' => true,
                'mailEnabled' => true,
                'publicBaseUrl' => null,
            ],
            $this->payload(),
        );

        $this->client->request(
            'GET',
            self::SETTINGS,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'requireEmailConfirmation' => false,
                'requireApproval' => true,
                'mailEnabled' => true,
                'publicBaseUrl' => null,
            ],
            $this->payload(),
        );
    }

    public function testPutPersistsThePublicBaseUrl(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'https://reader.example.ts.net/reader',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('https://reader.example.ts.net/reader', $this->payload()['publicBaseUrl']);
    }

    public function testPutRejectsAMalformedPublicBaseUrl(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody('PUT', $admin, [
            'requireEmailConfirmation' => true,
            'requireApproval' => true,
            'publicBaseUrl' => 'not a url',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testPutRejectsANonBooleanPayload(): void
    {
        $admin = $this->admin();

        $this->requestWithJsonBody(
            'PUT',
            $admin,
            ['requireEmailConfirmation' => 'nope', 'requireApproval' => true],
        );

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }

    public function testNonAdminIsForbidden(): void
    {
        $plain = $this->factory()->create('plain@example.com');

        $this->client->request(
            'GET',
            self::SETTINGS,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($plain)],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
    }
}
