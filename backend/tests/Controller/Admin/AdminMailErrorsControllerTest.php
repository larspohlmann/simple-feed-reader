<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Enum\MailKind;
use App\Service\Mail\MailFailureRecorder\MailDeliveryHealth;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

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
            server: $this->bearerFor($admin),
        );

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        $failures = $body['failures'];
        self::assertIsArray($failures);
        self::assertCount(1, $failures);
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
}
