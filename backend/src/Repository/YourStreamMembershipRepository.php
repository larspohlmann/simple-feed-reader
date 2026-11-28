<?php

declare(strict_types=1);

namespace App\Repository;

use App\Doctrine\BulkInsert;
use App\Entity\YourStreamEntry;
use App\Service\Curation\YourStream\Model\YourStreamMatchModel;
use App\Service\Curation\YourStream\YourStreamMembershipWriter\YourStreamMembershipWriterInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The one writer of your_stream_entry.
 *
 * @extends ServiceEntityRepository<YourStreamEntry>
 */
final class YourStreamMembershipRepository extends ServiceEntityRepository implements
    YourStreamMembershipWriterInterface
{
    /** Rows per INSERT: 5 placeholders each, kept under SQLite's historical 999. */
    private const int INSERT_ROWS = 150;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, YourStreamEntry::class);
    }

    public function insertMissing(array $matches, \DateTimeImmutable $matchedAt): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $insert = BulkInsert::into(
            \sprintf(
                'INSERT %s INTO your_stream_entry (user_id, entry_id, matched_at, score, matched_stems)',
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            self::INSERT_ROWS,
        );
        $stamp = $matchedAt->format('Y-m-d H:i:s');

        return $insert->rows($connection, array_map(
            static fn (YourStreamMatchModel $match): array => [
                $match->userId,
                $match->entryId,
                $stamp,
                \sprintf('%.6F', $match->score->score),
                $match->formatMatchedStems(),
            ],
            $matches,
        ));
    }
}
