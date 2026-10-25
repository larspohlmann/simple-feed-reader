<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\EntryAliases;
use PHPUnit\Framework\TestCase;

final class EntryAliasesTest extends TestCase
{
    public function testScopeKeysReadTheirOwnAliases(): void
    {
        $primary = EntryAliases::primary();
        $collapse = EntryAliases::collapse();

        self::assertSame('s.id', $primary->subscriptionKey());
        self::assertSame('nf.network', $primary->networkKey());
        self::assertSame('s2.id', $collapse->subscriptionKey());
        self::assertSame('nf2.network', $collapse->networkKey());
    }
}
