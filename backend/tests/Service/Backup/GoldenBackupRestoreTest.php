<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\User;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Backup\AccountRestorer;
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
 * oldest-supported.ndjson omits every additive field added since the format
 * shipped: `showReasons` and `profileText`, both inside the account line's
 * `recommendationSettings`. current.ndjson carries every field the format held when
 * the corpus was frozen. It is not extended when a field is added — freezing is the
 * point, and the standing rule below says so.
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

    private function settingsRepository(): RecommendationSettingsRepository
    {
        $repository = self::getContainer()->get(RecommendationSettingsRepository::class);
        self::assertInstanceOf(RecommendationSettingsRepository::class, $repository);

        return $repository;
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
    public static function corpus(): iterable
    {
        yield 'a file written before showReasons and profileText existed' =>
            ['oldest-supported.ndjson', 1, 0];
        yield 'a file carrying every field the format holds today' =>
            ['current.ndjson', 1, 1];
    }

    #[DataProvider('corpus')]
    public function testRestoresACommittedBackup(string $fixture, int $entries, int $states): void
    {
        $user = $this->makeUser('golden-' . $fixture . '@example.com');

        $result = $this->restorer()->restore($user, $this->fixture($fixture), self::CONFIRMATION);

        self::assertSame(1, $result->tags);
        self::assertSame(1, $result->subscriptions);
        self::assertSame($entries, $result->entries);
        self::assertSame($states, $result->entryStates);
    }

    public function testAFileWrittenBeforeAFieldExistedRestoresWithThatFieldsDefault(): void
    {
        $user = $this->makeUser('golden-defaults@example.com');

        $this->restorer()->restore($user, $this->fixture('oldest-supported.ndjson'), self::CONFIRMATION);
        $this->em->clear();

        $settings = $this->settingsRepository()->findForUser($user);
        self::assertNotNull($settings);
        self::assertFalse($settings->values()->showReasons);
        self::assertNull($settings->values()->profileText);
    }
}
