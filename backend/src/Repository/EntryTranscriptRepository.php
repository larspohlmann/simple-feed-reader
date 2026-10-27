<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryTranscript;
use App\Enum\TranscriptStatus;
use App\Service\Search\TranscriptText\TranscriptTextInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EntryTranscript> */
final class EntryTranscriptRepository extends ServiceEntityRepository implements TranscriptTextInterface
{
    /** Outlasts a worker sweep (5 fetches × TranscriptBudgetModel::FETCH_SECONDS) with room to spare. */
    public const string CLAIM_LEASE = 'PT5M';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryTranscript::class);
    }

    /** @return list<EntryTranscript> pending rows due at $now, newest entry first: a row is created with its entry */
    public function findClaimable(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<EntryTranscript> $rows */
        $rows = $this->due($now)
            ->innerJoin('transcript.entry', 'entry')
            ->innerJoin('entry.feed', 'feed')
            ->addSelect('entry', 'feed')
            ->orderBy('transcript.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * A claim pushes nextAttemptAt one lease out by a conditional UPDATE, so of two callers racing for a row exactly
     * one sees it change. A holder outliving its lease may fill a row twice; both write the same transcript.
     *
     * @param list<EntryTranscript> $rows
     *
     * @return list<EntryTranscript> the rows this call now holds
     */
    public function claim(array $rows, \DateTimeImmutable $now): array
    {
        $query = $this->getEntityManager()->createQuery(\sprintf(
            'UPDATE %s transcript SET transcript.nextAttemptAt = :leaseEnd WHERE transcript.id = :id'
            . ' AND transcript.status = :pending'
            . ' AND (transcript.nextAttemptAt IS NULL OR transcript.nextAttemptAt <= :now)',
            EntryTranscript::class,
        ))
            ->setParameter('leaseEnd', $now->add(new \DateInterval(self::CLAIM_LEASE)), Types::DATETIME_IMMUTABLE)
            ->setParameter('pending', TranscriptStatus::Pending)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE);

        $claimed = [];
        foreach ($rows as $row) {
            if ($query->setParameter('id', $row->requireId())->execute() === 1) {
                $claimed[] = $row;
            }
        }

        return $claimed;
    }

    public function findForEntry(Entry $entry): ?EntryTranscript
    {
        return $this->findOneBy(['entry' => $entry]);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array<int, string> the filled transcripts' search text, by entry id
     */
    public function searchTextsFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        /** @var list<array{entryId: int|string, searchText: string}> $rows */
        $rows = $this->createQueryBuilder('transcript')
            ->select('IDENTITY(transcript.entry) AS entryId', 'transcript.searchText AS searchText')
            ->andWhere('transcript.entry IN (:entryIds)')
            ->andWhere('transcript.status = :filled')
            ->setParameter('entryIds', $entryIds)
            ->setParameter('filled', TranscriptStatus::Filled)
            ->getQuery()
            ->getArrayResult();

        $texts = [];
        foreach ($rows as $row) {
            $texts[(int) $row['entryId']] = $row['searchText'];
        }

        return $texts;
    }

    private function due(\DateTimeImmutable $now): QueryBuilder
    {
        return $this->createQueryBuilder('transcript')
            ->andWhere('transcript.status = :pending')
            ->andWhere('(transcript.nextAttemptAt IS NULL OR transcript.nextAttemptAt <= :now)')
            ->setParameter('pending', TranscriptStatus::Pending)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE);
    }
}
