<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<AbbreviatedNameRule> */
final class AbbreviatedNameRuleTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/data/abbreviated-name-fixtures.php';

    /**
     * Keyed at the fixture's request, so the test never depends on the production allow-list.
     *
     * @var array<string, list<string>>
     */
    private array $wireNames = ['App\\Service\\Fixtures\\Wire\\WireRequest' => ['q']];

    protected function setUp(): void
    {
        parent::setUp();
        // ReflectionProvider::hasClass() only sees a fixture class once it is declared; analysing only parses it.
        require_once self::FIXTURE;
    }

    protected function getRule(): Rule
    {
        return new AbbreviatedNameRule(
            new NodeFinder(),
            self::getContainer()->getByType(ReflectionProvider::class),
            $this->wireNames,
        );
    }

    public function testItReportsEverySingleLetterAndTruncatedNameButCoordinatesInheritedParametersAndWireKeys(): void
    {
        $this->analyse([self::FIXTURE], [
            [self::message('idx'), 13],
            [self::message('cfg'), 15],
            [self::message('i'), 22],
            [self::message('i'), 23],
            [self::message('e'), 27],
            [self::message('n'), 28],
            [self::message('s1'), 34],
            [self::message('data'), 35],
            [self::message('data'), 37],
            [self::message('msg'), 43],
            [self::message('msg'), 45],
            [self::message('data'), 53],
            [self::message('data'), 55],
            [self::message('q'), 70],
            [self::message('idx'), 80],
        ]);
    }

    public function testAnEmptyAllowListReportsTheWireKeyToo(): void
    {
        $this->wireNames = [];

        $this->analyse([self::FIXTURE], [
            [self::message('idx'), 13],
            [self::message('cfg'), 15],
            [self::message('i'), 22],
            [self::message('i'), 23],
            [self::message('e'), 27],
            [self::message('n'), 28],
            [self::message('s1'), 34],
            [self::message('data'), 35],
            [self::message('data'), 37],
            [self::message('msg'), 43],
            [self::message('msg'), 45],
            [self::message('data'), 53],
            [self::message('data'), 55],
            [self::message('q'), 63],
            [self::message('q'), 70],
            [self::message('idx'), 80],
        ]);
    }

    private static function message(string $name): string
    {
        return sprintf(
            'Name reveals intent: $%s is a single letter or a truncated word; name what it holds (#1172).',
            $name,
        );
    }
}
