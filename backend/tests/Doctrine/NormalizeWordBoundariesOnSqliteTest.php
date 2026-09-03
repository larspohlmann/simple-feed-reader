<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Service\Search\WordBoundaries;
use App\Tests\DbTestCase;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * The function is read from the connection the APPLICATION uses, so the test
 * proves SqliteConnectionSetupDriver registered it, not that PHP can normalize.
 */
final class NormalizeWordBoundariesOnSqliteTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->em->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            self::markTestSkipped('The native function is SQLite-only; this leg runs on another platform.');
        }
    }

    public function testTheConnectionNormalizesLikePhpDoes(): void
    {
        $text = 'E-Mail (heute), „Corona-Krise“/US-Wahl!';

        self::assertSame(
            WordBoundaries::normalize($text),
            $this->em->getConnection()->fetchOne('SELECT NORMALIZE_WORD_BOUNDARIES(?)', [$text]),
        );
    }

    public function testANullHaystackStaysNull(): void
    {
        self::assertNull($this->em->getConnection()->fetchOne('SELECT NORMALIZE_WORD_BOUNDARIES(NULL)'));
    }
}
