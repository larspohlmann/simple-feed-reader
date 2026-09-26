<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\ReaderAudit\DatabaseValue;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** The reader audit's raw SQL: whose subscriptions it reads, and the entries it draws from them. */
final readonly class ReaderAuditRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function userIdNamed(string $idOrEmail): ?int
    {
        $column = ctype_digit($idOrEmail) ? 'id' : 'email';

        return self::idOrNull(
            $this->connection->fetchOne("SELECT id FROM app_user WHERE {$column} = :value", ['value' => $idOrEmail]),
        );
    }

    public function widestSubscriberId(): ?int
    {
        return self::idOrNull($this->connection->fetchOne(
            'SELECT user_id FROM subscription GROUP BY user_id ORDER BY COUNT(*) DESC, user_id ASC',
        ));
    }

    /** @return list<array<string, mixed>> feed_id and entry_id, ordered by feed, then entry */
    public function candidateRows(int $userId, \DateTimeImmutable $before): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT s.feed_id AS feed_id, e.id AS entry_id
               FROM subscription s
               JOIN entry e ON e.feed_id = s.feed_id
              WHERE s.user_id = :user AND e.url IS NOT NULL AND e.url <> \'\'
                AND e.created_at < :before
              ORDER BY s.feed_id, e.id',
            ['user' => $userId, 'before' => $before->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param list<int> $entryIds
     *
     * @return list<array<string, mixed>>
     */
    public function detailRows(array $entryIds, int $userId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT e.id, e.title, e.url, e.author, e.image_url, f.id AS feed_id,
                    CASE WHEN e.body_is_opening_post THEN NULL ELSE e.content_html END AS article_content_html,
                    f.title AS feed_title, f.url AS feed_url, s.id AS subscription_id
               FROM entry e
               JOIN feed f ON f.id = e.feed_id
               JOIN subscription s ON s.feed_id = f.id AND s.user_id = :user
              WHERE e.id IN (:ids)',
            ['user' => $userId, 'ids' => $entryIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    private static function idOrNull(mixed $id): ?int
    {
        return $id === false ? null : DatabaseValue::int($id);
    }
}
