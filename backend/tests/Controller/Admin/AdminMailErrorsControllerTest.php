<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\MailKind;
use App\Entity\User;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** `/api/admin/mail/errors` is covered by the `^/api/admin/` ROLE_ADMIN prefix rule. */
final class AdminMailErrorsControllerTest extends ApiTestCase
{
    private const string ERRORS = '/api/admin/mail/errors';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
    }

    public function testItReturnsTheRecentFailuresAndCountForAnAdmin(): void
    {
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');

        $admin = $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
        $this->client->request(
            'GET',
            self::ERRORS,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($admin)],
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertSame(1, $body['count']);
        $failures = $body['failures'];
        self::assertIsArray($failures);
        $firstFailure = $failures[0];
        self::assertIsArray($firstFailure);
        self::assertSame('digest', $firstFailure['kind']);
        self::assertSame('reader@example.test', $firstFailure['recipient']);
    }

    public function testItRefusesAnAnonymousRequest(): void
    {
        $this->client->request('GET', self::ERRORS);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager->create($user);
    }
}
