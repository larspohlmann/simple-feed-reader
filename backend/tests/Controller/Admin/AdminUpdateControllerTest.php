<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\AdminUpdateController;
use App\Entity\User;
use App\Service\SelfUpdate\SelfUpdater;
use App\Service\Version\Model\LatestReleaseModel;
use App\Service\Version\Model\ReleaseVersionModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\InMemoryUpdateStateStore;
use App\Tests\Support\VersionReporters;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

final class AdminUpdateControllerTest extends ApiTestCase
{
    private const string UPDATE = '/api/admin/update';

    private KernelBrowser $client;

    private string $stateDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->stateDirectory = self::getContainer()->getParameter('update_state_directory');
        (new Filesystem())->remove($this->stateDirectory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->stateDirectory);

        parent::tearDown();
    }

    public function testWithoutATokenIsRejected(): void
    {
        $this->client->request('GET', self::UPDATE);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testWithoutAStateDirectoryNoUpdaterIsPresentAndNothingIsUnderWay(): void
    {
        $this->client->request('GET', self::UPDATE, server: $this->bearerFor($this->admin()));

        self::assertResponseIsSuccessful();
        self::assertSame(
            [
                'updaterPresent' => false,
                'phase' => 'idle',
                'startedAt' => null,
                'finishedAt' => null,
                'exitCode' => null,
                'log' => '',
            ],
            $this->payload($this->client),
        );
    }

    public function testRequestingWithoutAnUpdaterIsServiceUnavailable(): void
    {
        $this->postAsAdmin();

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('updater_unavailable', $this->payload($this->client)['type']);
    }

    public function testRequestingWhileARequestIsPendingIsAConflict(): void
    {
        $this->writeState('outbox/heartbeat', (string) time());
        $this->writeState('inbox/request.json', '{"id":"0123456789abcdef","requestedBy":"boss@example.com"}');

        $this->postAsAdmin();

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('update_in_progress', $this->payload($this->client)['type']);
    }

    /** The test env reports no latest release (VersionControllerTest), so there is never an update to take. */
    public function testRequestingWithoutANewerReleaseIsAConflict(): void
    {
        $this->writeState('outbox/heartbeat', (string) time());

        $this->postAsAdmin();

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('no_update_available', $this->payload($this->client)['type']);
    }

    /** The kernel tests above can never reach an available release; this drives the action directly. */
    public function testAnAcceptedRequestAnswersWithTheRequestedStatus(): void
    {
        $state = new InMemoryUpdateStateStore(heartbeat: new \DateTimeImmutable('2026-10-31 09:59:30'));
        $updater = new SelfUpdater(
            $state,
            VersionReporters::reporting(
                new ReleaseVersionModel('v1.0.0', 'abc123', '2026-01-01T00:00:00Z'),
                new LatestReleaseModel('v1.1.0', 'https://github.test/releases/tag/v1.1.0'),
            ),
            new MockClock('2026-10-31 10:00:00'),
        );

        $response = (new AdminUpdateController($updater))->request($this->admin());

        self::assertSame(Response::HTTP_ACCEPTED, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['updaterPresent']);
        self::assertSame('requested', $payload['phase']);
        self::assertSame('boss@example.com', $state->savedRequests[0]->requestedBy);
    }

    private function admin(): User
    {
        return $this->factory()->create('boss@example.com', roles: ['ROLE_ADMIN']);
    }

    private function postAsAdmin(): void
    {
        $this->client->request('POST', self::UPDATE, server: $this->bearerFor($this->admin()));
    }

    private function writeState(string $name, string $contents): void
    {
        (new Filesystem())->dumpFile($this->stateDirectory . '/' . $name, $contents);
    }
}
