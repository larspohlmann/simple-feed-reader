<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<PersistenceClassesAreFinalRule> */
final class PersistenceClassesAreFinalRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new PersistenceClassesAreFinalRule();
    }

    public function testItReportsEveryClassInTheEntitiesAndRepositoriesThatIsNotFinal(): void
    {
        $this->analyse(
            [__DIR__ . '/data/persistence-classes-are-final-fixtures.php'],
            [
                [self::message('App\Entity\Fixtures\OpenEntity'), 9],
                [self::message('App\Repository\Fixtures\AbstractBaseRepository'), 28],
            ],
        );
    }

    private static function message(string $className): string
    {
        return sprintf(
            'Persistence classes are final: %s is not. '
            . 'A test doubles the interface its consumer owns, never the persistence class (#1169).',
            $className,
        );
    }
}
