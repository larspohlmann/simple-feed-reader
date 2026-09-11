<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryAliases;
use App\Repository\UnreadDql;
use PHPUnit\Framework\TestCase;

final class UnreadDqlTest extends TestCase
{
    public function testDefaultAliasesMatchThePrimaryQuery(): void
    {
        self::assertSame(
            'es.isHidden = :notHidden OR (es.isHidden IS NULL AND '
            . '(s.markedReadUntil IS NULL OR e.effectiveDate > s.markedReadUntil))',
            UnreadDql::predicate(),
        );
    }

    public function testCollapseAliasesRewriteEveryReference(): void
    {
        self::assertSame(
            'es2.isHidden = :notHidden OR (es2.isHidden IS NULL AND '
            . '(s2.markedReadUntil IS NULL OR e2.effectiveDate > s2.markedReadUntil))',
            UnreadDql::predicate(EntryAliases::collapse()),
        );
    }
}
