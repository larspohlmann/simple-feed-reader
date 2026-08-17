<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\Backup\AccountBackupExporter;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountBackupControllerTest extends WebTestCase
{
    /** @return array{0: array<string, string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    /**
     * Seeds one tag, one feed, one subscription and one entry for $user, then
     * exports the account through the real exporter — so the fixture and the
     * production export format can never quietly disagree.
     */
    private function seededBackupFor(User $user): string
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://restore-fixture.example/feed.xml');
        $em->persist($feed);
        $em->persist(new Tag($user, 'Restore Tag'));
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $now = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $em->persist(new Entry(
            $feed,
            'restore-fixture-guid-1',
            'https://restore-fixture.example/1',
            'Entry One',
            $now,
            $now,
        ));
        $em->flush();

        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);
        $ndjson = '';
        foreach ($exporter->lines($user, 'https://source.example') as $line) {
            $ndjson .= $line . "\n";
        }

        return (string) gzencode($ndjson);
    }

    public function testBackupRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/account/backup');
        self::assertResponseStatusCodeSame(401);
    }

    public function testBackupStreamsAGzipNdjsonAttachment(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('backup-download@example.com');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feed = new Feed('https://dl.example/feed.xml');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $em->flush();

        $client->request('GET', '/api/account/backup', server: $headers);

        // Symfony's BrowserKit captures StreamedResponse's echoed content into the internal response
        $internalResponse = $client->getInternalResponse();
        $streamed = $internalResponse->getContent();

        self::assertResponseIsSuccessful();
        self::assertSame('application/gzip', $client->getResponse()->headers->get('Content-Type'));
        self::assertNull($client->getResponse()->headers->get('Content-Encoding'));
        self::assertStringContainsString(
            'attachment; filename="account-backup-',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );

        $ndjson = gzdecode($streamed);
        self::assertIsString($ndjson);
        $lines = explode("\n", trim($ndjson));
        $first = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertSame('header', $first['kind']);
        $last = json_decode($lines[array_key_last($lines)], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($last);
        self::assertSame('footer', $last['kind']);
        self::assertIsArray($last['counts']);
        self::assertSame(1, $last['counts']['subscription']);
    }

    public function testPreviewReportsLoadAndDeleteCounts(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('restore-preview@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->seededBackupFor($user);

        $client->request(
            'POST',
            '/api/account/restore/preview',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: $gzip,
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['toLoad']);
        self::assertIsArray($body['toDelete']);
        self::assertIsArray($body['backup']);
        self::assertSame(1, $body['toLoad']['subscriptions']);
        self::assertSame(1, $body['toDelete']['subscriptions']);
        self::assertSame('restore-preview@example.com', $body['backup']['sourceEmail']);

        // A preview must be read-only: it inspects and counts, it never wipes.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
        self::assertSame(1, $subscriptions->countForUser($userId));
    }

    public function testRestoreWithoutConfirmIs422AndDeletesNothing(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('restore-no-confirm@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->seededBackupFor($user);

        $client->request(
            'POST',
            '/api/account/restore',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: $gzip,
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('validation_error', $body['type']);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
        self::assertSame(1, $subscriptions->countForUser($userId));
    }

    public function testRestoreRunsEndToEndOverHttp(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('restore-end-to-end@example.com');
        $this->seededBackupFor($user);

        $client->request('GET', '/api/account/backup', server: $headers);
        $exported = $client->getInternalResponse()->getContent();
        self::assertResponseIsSuccessful();

        $client->request(
            'POST',
            '/api/account/restore?confirm=REPLACE',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: $exported,
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['loaded']);
        self::assertSame(1, $body['loaded']['tags']);
        self::assertSame(1, $body['loaded']['subscriptions']);

        $client->request('GET', '/api/subscriptions', server: $headers);
        self::assertResponseIsSuccessful();
        $subscriptionsBody = json_decode(
            (string) $client->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($subscriptionsBody);
        self::assertIsArray($subscriptionsBody['subscriptions']);
        self::assertCount(1, $subscriptionsBody['subscriptions']);
        $subscription = $subscriptionsBody['subscriptions'][0];
        self::assertIsArray($subscription);
        self::assertSame('https://restore-fixture.example/feed.xml', $subscription['feedUrl']);
    }

    public function testGarbageBodyIs422InvalidBackup(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('restore-garbage@example.com');

        $client->request(
            'POST',
            '/api/account/restore/preview',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: 'not gzip',
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('invalid_backup', $body['type']);
    }
}
