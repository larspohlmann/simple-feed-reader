<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\VersionController;
use App\Service\Version\LatestRelease;
use App\Service\Version\LatestReleaseReader;
use App\Service\Version\ReleaseVersion;
use App\Service\Version\ReleaseVersionReader;
use App\Service\Version\VersionReporter;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VersionControllerTest extends WebTestCase
{
    private function bearerToken(): string
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var JWTTokenManagerInterface $tokens */
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $tokens->create((new UserFactory($entityManager, $hasher))->create('version@example.com'));
    }

    /**
     * The route carries no access_control rule of its own — it is covered by the
     * `^/api/` catch-all. Asserted through the real firewall, because that is
     * the only thing that proves the catch-all still reaches it.
     */
    public function testAnonymousIs401(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/version');

        self::assertResponseStatusCodeSame(401);
    }

    public function testReportsTheRunningBuild(): void
    {
        $client = self::createClient();
        $token = $this->bearerToken();

        $client->request('GET', '/api/version', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('version', $payload);
        self::assertArrayHasKey('commit', $payload);
        self::assertArrayHasKey('builtAt', $payload);
        // The test checkout has no deployed version.json, so this is the
        // development fallback rather than a tag.
        self::assertSame('dev', $payload['version']);
    }

    /**
     * The update check is folded into the same payload, and the suite must
     * reach it without ever calling GitHub: GITHUB_RELEASE_REPOSITORY is forced
     * empty in phpunit.dist.xml, so the reader returns null and the endpoint
     * reports no update. A non-null `latest` here would mean a live call leaked
     * into the test run.
     */
    public function testExposesTheUpdateCheckWithoutReachingGitHub(): void
    {
        $client = self::createClient();
        $token = $this->bearerToken();

        $client->request('GET', '/api/version', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('latest', $payload);
        self::assertArrayHasKey('updateAvailable', $payload);
        self::assertNull($payload['latest']);
        self::assertFalse($payload['updateAvailable']);
    }

    /**
     * The empty-repository test above can only ever see a null latest, so it
     * cannot pin how a real release is shaped into the payload. This drives the
     * action with a populated report — no kernel, no network — to assert the
     * nested `latest` object and the `updateAvailable` flag it carries.
     */
    public function testMapsAnAvailableReleaseIntoThePayload(): void
    {
        $reporter = $this->reporterReporting(
            new ReleaseVersion('v1.0.0', 'abc123', '2026-01-01T00:00:00Z'),
            new LatestRelease('v1.1.0', 'https://github.test/releases/tag/v1.1.0'),
        );

        $response = (new VersionController())($reporter);

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('v1.0.0', $payload['version']);
        self::assertTrue($payload['updateAvailable']);
        self::assertSame(
            ['version' => 'v1.1.0', 'notesUrl' => 'https://github.test/releases/tag/v1.1.0'],
            $payload['latest'],
        );
    }

    private function reporterReporting(ReleaseVersion $running, LatestRelease $latest): VersionReporter
    {
        $releaseReader = new class ($running) implements ReleaseVersionReader {
            public function __construct(private readonly ReleaseVersion $version)
            {
            }

            public function read(): ReleaseVersion
            {
                return $this->version;
            }
        };

        $latestReader = new class ($latest) implements LatestReleaseReader {
            public function __construct(private readonly LatestRelease $latest)
            {
            }

            public function read(): ?LatestRelease
            {
                return $this->latest;
            }
        };

        return new VersionReporter($releaseReader, $latestReader);
    }
}
