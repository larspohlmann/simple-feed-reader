<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\User;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\EntryPartRestorer;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Standing rule: an additive field adds NOTHING to this corpus.
 * `oldest-supported/` is frozen forever the moment it is created; only
 * `current/` moves when the format changes.
 */
final class GoldenBackupRestoreTest extends DbTestCase
{
    private const string CONFIRMATION = 'REPLACE';

    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email, locale: 'de');
    }

    private function restorer(): AccountRestorer
    {
        $restorer = self::getContainer()->get(AccountRestorer::class);
        self::assertInstanceOf(AccountRestorer::class, $restorer);

        return $restorer;
    }

    private function entryPartRestorer(): EntryPartRestorer
    {
        $restorer = self::getContainer()->get(EntryPartRestorer::class);
        self::assertInstanceOf(EntryPartRestorer::class, $restorer);

        return $restorer;
    }

    /**
     * AccountRestorer::start() ends with AccountReset's clear(), which
     * detaches the caller's User — the entries endpoint needs a managed one.
     */
    private function reloadUser(int $userId): User
    {
        $this->em->clear();
        $user = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function gzipOfFixture(string $directory, string $file): string
    {
        $path = __DIR__ . '/../../Fixtures/backup/' . $directory . '/' . $file;
        $gzip = gzencode((string) file_get_contents($path));
        self::assertIsString($gzip);

        return $gzip;
    }

    /** @return iterable<string, array{string}> */
    public static function supportedCorpus(): iterable
    {
        yield 'the current contract' => ['current'];
        yield 'the oldest supported contract' => ['oldest-supported'];
    }

    #[DataProvider('supportedCorpus')]
    public function testRestoresACommittedBackupDirectory(string $directory): void
    {
        $user = $this->makeUser('golden-' . $directory . '@example.com');
        $userId = (int) $user->getId();

        $started = $this->restorer()->start(
            $user,
            $this->gzipOfFixture($directory, '000-foundation.ndjson'),
            self::CONFIRMATION,
        );

        self::assertSame(1, $started->tags);
        self::assertSame(1, $started->subscriptions);

        $entriesResult = $this->entryPartRestorer()->load(
            $this->reloadUser($userId),
            $this->gzipOfFixture($directory, '001-entries.ndjson'),
        );

        self::assertSame(1, $entriesResult->entries);
        self::assertSame(1, $entriesResult->entryStates);
    }

    public function testAVersionTwoHeaderIsRejected(): void
    {
        $user = $this->makeUser('golden-rejected-version-2@example.com');
        $line = json_encode([
            'kind' => 'header',
            'schemaVersion' => 2,
            'createdAt' => '2026-08-20T11:03:57+00:00',
            'sourceUrl' => 'https://old.example',
            'sourceEmail' => 'old@example.com',
            'backupId' => 'golden-version-2',
            'part' => 0,
            'parts' => 1,
            'totals' => ['entries' => 0, 'entryStates' => 0],
        ], \JSON_THROW_ON_ERROR);
        $gzip = gzencode($line . "\n");
        self::assertIsString($gzip);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Unsupported schema version 2');

        $this->restorer()->start($user, $gzip, self::CONFIRMATION);
    }
}
