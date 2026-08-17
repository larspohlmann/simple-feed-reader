<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\Backup\AccountBackupExporter;
use App\Tests\Support\CorruptGzip;
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
        $entry = new Entry(
            $feed,
            'restore-fixture-guid-1',
            'https://restore-fixture.example/1',
            'Entry One',
            $now,
            $now,
        );
        $em->persist($entry);
        $state = new EntryState($user, $entry);
        $state->setIsRead(true);
        $em->persist($state);
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

    public function testRestorePreviewRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/account/restore/preview');
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * The one endpoint on this instance that deletes an account's contents.
     * A firewall or route-attribute regression here is the worst one there
     * is, and nothing else in the suite would notice it.
     */
    public function testRestoreRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/account/restore?confirm=REPLACE');
        self::assertResponseStatusCodeSame(401);
    }

    public function testACorruptGzipBodyIs422InvalidBackupRatherThan500(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('restore-corrupt@example.com');

        $client->request(
            'POST',
            '/api/account/restore/preview',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: CorruptGzip::bytes(),
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('invalid_backup', $body['type']);
    }

    /**
     * The preview test above proves a corrupt gzip is refused; it never
     * touches the destructive route. Pass 1 runs before the wipe on either
     * route, so this is low risk -- but nothing else proves it for the one
     * route where getting it wrong deletes the account.
     */
    public function testACorruptGzipBodyToTheDestructiveRouteIs422AndDeletesNothing(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('restore-corrupt-destructive@example.com');
        $userId = (int) $user->getId();
        $this->seededBackupFor($user);

        $client->request(
            'POST',
            '/api/account/restore?confirm=REPLACE',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: CorruptGzip::bytes(),
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('invalid_backup', $body['type']);

        $this->assertAccountRowsSurvived($userId);
    }

    /**
     * The grammar refusal has to protect the account exactly as the fit
     * refusal does: a footer whose counts disagree with the lines above it is
     * the truncation guard, and it fires before anything is deleted.
     */
    public function testAMiscountedFooterIs422AndDeletesNothing(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('restore-bad-footer@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->withAMiscountedFooter($this->seededBackupFor($user));

        $client->request(
            'POST',
            '/api/account/restore?confirm=REPLACE',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: $gzip,
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('invalid_backup', $body['type']);

        $this->assertAccountRowsSurvived($userId);
    }

    /**
     * A feed url declared twice would otherwise make RestoreLoadPass::loadFeed
     * persist two rows for it and violate feed.url's unique index — but only
     * once the wipe has already run, because loadFeed re-queries per line and
     * never sees its own unflushed Feed. A url the account already carried
     * before the restore would not reproduce that: the pre-existing row would
     * absorb both lines. This fixture declares a url the account has never
     * seen, twice, so the tally has to catch it in pass 1, before deleting
     * anything.
     */
    public function testADuplicateFeedUrlIs422AndDeletesNothing(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('restore-duplicate-feed@example.com');
        $userId = (int) $user->getId();
        $gzip = $this->withANewFeedUrlDeclaredTwice($this->seededBackupFor($user));

        $client->request(
            'POST',
            '/api/account/restore?confirm=REPLACE',
            server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
            content: $gzip,
        );

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('invalid_backup', $body['type']);

        $this->assertAccountRowsSurvived($userId);
    }

    /**
     * Adds two feed lines for a url the fixture never otherwise declares, and
     * keeps the footer's feed count in step, so the failure this proves is
     * the duplicate-url refusal and not an unrelated miscounted-footer one.
     */
    private function withANewFeedUrlDeclaredTwice(string $gzip): string
    {
        $newFeedLine = json_encode([
            'kind' => 'feed',
            'url' => 'https://restore-fixture-duplicate.example/feed.xml',
            'siteUrl' => null,
            'title' => null,
            'description' => null,
            'faviconUrl' => null,
            'sourceFormat' => 'xml',
        ], \JSON_THROW_ON_ERROR);

        $lines = [];
        foreach (explode("\n", (string) gzdecode($gzip)) as $line) {
            if ('' === $line) {
                continue;
            }
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            if ('feed' === ($decoded['kind'] ?? null)) {
                $lines[] = $line;
                $lines[] = $newFeedLine;
                $lines[] = $newFeedLine;

                continue;
            }
            if ('footer' === ($decoded['kind'] ?? null)) {
                $counts = $decoded['counts'];
                self::assertIsArray($counts);
                self::assertIsInt($counts['feed']);
                $counts['feed'] = $counts['feed'] + 2;
                $decoded['counts'] = $counts;
                $line = json_encode($decoded, \JSON_THROW_ON_ERROR);
            }
            $lines[] = $line;
        }

        return (string) gzencode(implode("\n", $lines) . "\n");
    }

    /**
     * Read through the connection after an explicit clear(): AccountReset
     * deletes with bulk DQL, so a stale identity map would let these pass
     * even on an emptied account.
     */
    private function assertAccountRowsSurvived(int $userId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        foreach (['tag', 'subscription', 'entry_state'] as $table) {
            $counted = $em->getConnection()->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE user_id = ?', $table),
                [$userId],
            );
            self::assertIsNumeric($counted);
            self::assertSame(
                1,
                (int) $counted,
                sprintf('the refusal deleted the account\'s %s rows', $table),
            );
        }
    }

    /** Claims one more entry than the file carries — a truncated download's signature. */
    private function withAMiscountedFooter(string $gzip): string
    {
        $lines = [];
        foreach (explode("\n", (string) gzdecode($gzip)) as $line) {
            if ('' === $line) {
                continue;
            }
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            if ('footer' === ($decoded['kind'] ?? null)) {
                $counts = $decoded['counts'];
                self::assertIsArray($counts);
                self::assertIsInt($counts['entry']);
                $counts['entry'] = $counts['entry'] + 1;
                $decoded['counts'] = $counts;
            }
            $lines[] = json_encode($decoded, \JSON_THROW_ON_ERROR);
        }

        return (string) gzencode(implode("\n", $lines) . "\n");
    }
}
