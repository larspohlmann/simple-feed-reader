<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Entry;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class FoldCaseRenderingTest extends DbTestCase
{
    public function testSqliteCallsTheNativeFunctionAndMysqlLeavesTheColumnToItsCollation(): void
    {
        $sql = $this->entityManager
            ->createQuery(\sprintf('SELECT FOLD_CASE(e.headline.title) FROM %s e', Entry::class))
            ->getSQL();
        self::assertIsString($sql);

        if ($this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::assertStringContainsString('FOLD_CASE(', $sql);

            return;
        }

        self::assertStringNotContainsString('FOLD_CASE', $sql);
        self::assertStringNotContainsString('LOWER(', $sql);
    }
}
