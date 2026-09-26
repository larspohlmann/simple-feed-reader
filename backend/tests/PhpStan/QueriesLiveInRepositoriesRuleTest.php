<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<QueriesLiveInRepositoriesRule> */
final class QueriesLiveInRepositoriesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new QueriesLiveInRepositoriesRule(new NodeFinder(), ['App\Service\Fixtures\LegacyQuery']);
    }

    public function testItReportsQueriesOutsideRepositoriesTheOrmExtensionsAndTests(): void
    {
        $this->analyse(
            [__DIR__ . '/data/queries-live-in-repositories-fixtures.php'],
            [
                [self::message('App\Service\Fixtures\BuildsDql', '->createQuery()'), 21],
                [self::message('App\Service\Fixtures\BuildsDql', 'Doctrine\ORM\QueryBuilder'), 24],
                [self::message('App\Service\Fixtures\BuildsDql', '->createQueryBuilder()'), 26],
                [self::message('App\Service\Fixtures\HoldsTheConnection', 'Doctrine\DBAL\Connection'), 32],
                [self::message('App\Controller\Fixtures\ReachesForTheConnection', '->getConnection()'), 80],
            ],
        );
    }

    private static function message(string $className, string $used): string
    {
        return sprintf(
            'Queries live in src/Repository: %s uses %s. Move the query into a repository method (#1170).',
            $className,
            $used,
        );
    }
}
