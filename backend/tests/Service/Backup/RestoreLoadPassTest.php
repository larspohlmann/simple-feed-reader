<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\User;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\Backup\EntryBatchInserter;
use App\Service\Backup\Exception\BackupLoadFailedException;
use App\Service\Backup\RestoreEntryLoader;
use App\Service\Backup\RestoreLoadPass;
use App\Service\Search\EntryIndexer;
use App\Service\Url\UrlNormalizer;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * A narrow unit test for the one branch AccountRestorerTest can no longer
 * reach through content: #412's final review closed every route by which a
 * crafted-but-otherwise-valid backup file could still make the database
 * refuse a value (BackupTally now catches a duplicate tag, feed or
 * subscription in pass 1; RestoreEntryLoader dedupes a repeated entry line by
 * design; a repeated entry_state line collides in Doctrine's own identity
 * map before it reaches SQL). What is left of "the database rejects a value"
 * is a driver failure with no content behind it at all — a schema mismatch,
 * a dropped connection, a column too narrow for a title the grammar never
 * bounds. That is not reproducible through the real service graph without
 * corrupting the schema mid-test, which itself breaks the MySQL leg's
 * transactional test isolation. A fake EntityManager whose flush() throws is
 * the direct way to prove RestoreLoadPass still wraps it.
 */
final class RestoreLoadPassTest extends TestCase
{
    public function testADatabaseFailureDuringTheAccountShapeFlushIsAWrappedBackupError(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('flush')->willThrowException($this->dbalException());

        $pass = new RestoreLoadPass(
            $em,
            $this->createStub(FeedRepository::class),
            $this->createStub(EntryRepository::class),
            $this->harmlessEntryLoader($em),
        );

        $this->expectException(BackupLoadFailedException::class);
        $pass->run(new User('flush-fails@example.com', new \DateTimeImmutable('2026-08-01')), (function () {
            yield from [];
        })());
    }

    /**
     * A real RestoreEntryLoader, wired to test doubles throughout: it is
     * never actually called in this scenario (the account-shape flush throws
     * first), but RestoreLoadPass's constructor takes the concrete class, so
     * it needs a valid instance rather than a mock of a `final` one.
     */
    private function harmlessEntryLoader(EntityManagerInterface $em): RestoreEntryLoader
    {
        return new RestoreEntryLoader(
            $em,
            $this->createStub(EntryRepository::class),
            new EntryBatchInserter($this->createStub(Connection::class), new UrlNormalizer()),
            new EntryIndexer(new RecordingSearchIndexWriter(), new NullLogger()),
            new MockClock('2026-08-01 00:00:00', 'UTC'),
        );
    }

    private function dbalException(): DbalException
    {
        return new class ('the database rejected a value') extends \Exception implements DbalException {
        };
    }
}
