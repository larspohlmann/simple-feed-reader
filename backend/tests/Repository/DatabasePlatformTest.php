<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\DatabasePlatform;
use App\Tests\DbTestCase;

final class DatabasePlatformTest extends DbTestCase
{
    public function testTheUpsertClauseOverwritesTheListedColumnsWithTheIncomingValues(): void
    {
        $connection = $this->entityManager->getConnection();

        $clause = DatabasePlatform::buildUpsertClause($connection, ['user_id', 'item_id'], ['score', 'voted_at']);

        self::assertSame(
            DatabasePlatform::isMySql($connection)
                ? 'ON DUPLICATE KEY UPDATE score = VALUES(score), voted_at = VALUES(voted_at)'
                : 'ON CONFLICT (user_id, item_id) DO UPDATE SET score = excluded.score, voted_at = excluded.voted_at',
            $clause,
        );
    }
}
