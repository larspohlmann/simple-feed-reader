<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SharedLinkResolution;
use App\Service\Content\LinkResolution\Model\LinkLandingModel;
use App\Service\Content\LinkResolution\Model\PendingLinkModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SharedLinkResolution> */
final class SharedLinkResolutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedLinkResolution::class);
    }

    /** @return list<PendingLinkModel> the queued links due at $now, oldest first */
    public function findDuePending(\DateTimeImmutable $now, int $limit): array
    {
        /** @var list<array{shared_link_id: int|string, url: string, attempts: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT resolution.shared_link_id, link.url, resolution.attempts FROM shared_link_resolution resolution'
            . ' JOIN shared_link link ON link.id = resolution.shared_link_id'
            . " WHERE resolution.status = 'pending' AND (resolution.retry_at IS NULL OR resolution.retry_at <= ?)"
            . ' ORDER BY resolution.shared_link_id LIMIT ' . $limit,
            [$now->format('Y-m-d H:i:s')],
        );

        return array_map(
            static fn (array $row): PendingLinkModel => new PendingLinkModel(
                (int) $row['shared_link_id'],
                $row['url'],
                (int) $row['attempts'],
            ),
            $rows,
        );
    }

    /** Writes nothing when another pass settled or retried the link since it was read. */
    public function settle(PendingLinkModel $link, LinkLandingModel $landing): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE shared_link_resolution SET status = ?, resolved_url = ?, resolved_url_hash = ?,'
            . ' attempts = attempts + 1, retry_at = NULL'
            . " WHERE shared_link_id = ? AND status = 'pending' AND attempts = ?",
            [$landing->resolution->value, $landing->url, $landing->urlHash, $link->linkId, $link->attempts],
        );
    }

    /** As settle(), but the link stays pending until $retryAt. */
    public function defer(PendingLinkModel $link, \DateTimeImmutable $retryAt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE shared_link_resolution SET attempts = attempts + 1, retry_at = ?'
            . " WHERE shared_link_id = ? AND status = 'pending' AND attempts = ?",
            [$retryAt->format('Y-m-d H:i:s'), $link->linkId, $link->attempts],
        );
    }

    /**
     * A link already queued or settled keeps its state.
     *
     * @param list<int> $linkIds
     */
    public function queue(array $linkIds): void
    {
        if ($linkIds === []) {
            return;
        }
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            sprintf(
                "INSERT %s INTO shared_link_resolution (shared_link_id, status, attempts)"
                . " SELECT id, 'pending', 0 FROM shared_link WHERE id IN (?)",
                DatabasePlatform::insertIgnoreClause($connection),
            ),
            [$linkIds],
            [ArrayParameterType::INTEGER],
        );
    }
}
