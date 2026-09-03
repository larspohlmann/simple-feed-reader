<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Entry;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * Which rendering each engine gets. SQLite must never see the REPLACE chain:
 * that is the shape that overflowed its parser stack (#584).
 */
final class NormalizeWordBoundariesRenderingTest extends DbTestCase
{
    public function testSqliteCallsTheNativeFunctionAndMysqlUnrollsTheReplaceChain(): void
    {
        $sql = $this->em
            ->createQuery(\sprintf('SELECT NORMALIZE_WORD_BOUNDARIES(e.title) FROM %s e', Entry::class))
            ->getSQL();
        self::assertIsString($sql);

        if ($this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::assertStringContainsString('NORMALIZE_WORD_BOUNDARIES(', $sql);
            self::assertStringNotContainsString('REPLACE(', $sql);

            return;
        }

        self::assertStringContainsString('REPLACE(REPLACE(', $sql);
        self::assertStringNotContainsString('NORMALIZE_WORD_BOUNDARIES', $sql);
    }
}
