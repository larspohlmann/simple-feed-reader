<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\User;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\Exception\InvalidBackupException;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Committed backup files, restored on every run. Task 4 (AccountBackupExporterTest)
 * guards what the exporter writes; this guards what the reader still accepts. A
 * field made required tomorrow would reject every file written before today, and
 * only a frozen file — one nothing here ever regenerates — can catch that (#556).
 *
 * The two fixtures under tests/Fixtures/backup/ are committed as plain NDJSON, not
 * gzip: the tests gzip them on read, and a committed .gz would be unreviewable in a
 * diff. They are hand-written, never produced by running today's exporter — a
 * fixture regenerated from today's code could not test yesterday's format, which is
 * the only reason this corpus exists.
 *
 * The two version-1 fixtures stay frozen as rejection evidence after the format
 * moves to version 2. version-2.ndjson names the new supported contract.
 *
 * Standing rule: when a PR adds an additive field, it adds NOTHING to this corpus.
 * oldest-supported already lacks the field, and that absence IS the test. A third
 * fixture appears only when support for something is first DROPPED. NDJSON has no
 * comment syntax, so this rule lives here rather than inside the fixture files
 * themselves — inventing a comment line there would make BackupReader reject the
 * file.
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

    private function fixture(string $name): string
    {
        $gzip = gzencode((string) file_get_contents(__DIR__ . '/../../Fixtures/backup/' . $name));
        self::assertIsString($gzip);

        return $gzip;
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function supportedCorpus(): iterable
    {
        yield 'the current version-2 contract' => ['version-2.ndjson', 1, 1];
    }

    #[DataProvider('supportedCorpus')]
    public function testRestoresACommittedBackup(string $fixture, int $entries, int $states): void
    {
        $user = $this->makeUser('golden-' . $fixture . '@example.com');

        $result = $this->restorer()->restore($user, $this->fixture($fixture), self::CONFIRMATION);

        self::assertSame(1, $result->tags);
        self::assertSame(1, $result->subscriptions);
        self::assertSame($entries, $result->entries);
        self::assertSame($states, $result->entryStates);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedCorpus(): iterable
    {
        yield 'the oldest version-1 fixture' => ['oldest-supported.ndjson'];
        yield 'the current version-1 fixture' => ['current.ndjson'];
    }

    #[DataProvider('unsupportedCorpus')]
    public function testVersionOneFixturesFailOnlyBecauseTheirSchemaVersionIsUnsupported(string $fixture): void
    {
        $user = $this->makeUser('golden-rejected-' . $fixture . '@example.com');

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('Unsupported schema version 1; this instance reads version 2.');

        $this->restorer()->restore($user, $this->fixture($fixture), self::CONFIRMATION);
    }
}
