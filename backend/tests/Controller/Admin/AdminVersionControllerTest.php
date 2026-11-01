<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AdminVersionController;
use App\Service\Version\Model\LatestReleaseModel;
use App\Service\Version\Model\ReleaseVersionModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\VersionReporters;
use Symfony\Component\HttpFoundation\Response;

final class AdminVersionControllerTest extends ApiTestCase
{
    private const string CHECK = '/api/admin/version/check';

    public function testWithoutATokenIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', self::CHECK);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnswersWithTheVersionPayload(): void
    {
        $client = self::createClient();
        $admin = $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);

        $client->request('POST', self::CHECK, server: $this->bearerFor($admin));

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('dev', $payload['version']);
        self::assertNull($payload['latest']);
        self::assertFalse($payload['updateAvailable']);
    }

    /** The kernel test above only ever sees a null `latest`; this drives the action directly with a real release. */
    public function testMapsANewerReleaseIntoThePayload(): void
    {
        $reporter = VersionReporters::reporting(
            new ReleaseVersionModel('v1.0.0', 'abc123', '2026-01-01T00:00:00Z'),
            new LatestReleaseModel('v1.1.0', 'https://github.test/releases/tag/v1.1.0'),
        );

        $response = (new AdminVersionController($reporter))->check();

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['updateAvailable']);
        self::assertSame(
            ['version' => 'v1.1.0', 'notesUrl' => 'https://github.test/releases/tag/v1.1.0'],
            $payload['latest'],
        );
    }
}
