<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Repository\QuotedLinkJoin;
use App\Tests\DbTestCase;

final class QuotedLinkJoinTest extends DbTestCase
{
    public function testTheQuotedLinkIsFetchedWithTheEntry(): void
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()->select('e')->from(Entry::class, 'e');

        $dql = QuotedLinkJoin::onto($queryBuilder, 'e')->getDQL();

        self::assertSame(
            'SELECT e, e_quoted_link FROM ' . Entry::class . ' e LEFT JOIN e.quotedLink e_quoted_link',
            $dql,
        );
    }
}
