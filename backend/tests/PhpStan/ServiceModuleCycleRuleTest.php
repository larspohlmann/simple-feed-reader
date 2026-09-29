<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ServiceModuleCycleRule> */
final class ServiceModuleCycleRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ServiceModuleCycleRule();
    }

    protected function getCollectors(): array
    {
        return [new ServiceModuleDependencyCollector(new NodeFinder())];
    }

    public function testATwoModuleCycleIsReportedOnceWhereItCloses(): void
    {
        $this->analyse([self::fixture('two-module-cycle')], [[self::message('Alpha -> Beta -> Alpha'), 26]]);
    }

    public function testAThreeModuleCycleNamesEveryModuleOnItsPath(): void
    {
        $this->analyse(
            [self::fixture('three-module-cycle')],
            [[self::message('Alpha -> Beta -> Gamma -> Alpha'), 36]],
        );
    }

    public function testAnAcyclicGraphIsClean(): void
    {
        $this->analyse([self::fixture('acyclic')], []);
    }

    public function testAClassLooseInTheServiceRootIsAModuleOfItsOwn(): void
    {
        $this->analyse([self::fixture('LooseService')], [[self::message('Alpha -> LooseService -> Alpha'), 9]]);
    }

    public function testEveryDistinctCycleIsReported(): void
    {
        $this->analyse(
            [self::fixture('two-cycles')],
            [
                [self::message('Alpha -> Beta -> Alpha'), 23],
                [self::message('Delta -> Gamma -> Delta'), 33],
            ],
        );
    }

    public function testModulesAreWalkedInNameOrder(): void
    {
        $this->analyse([self::fixture('beta-first')], [[self::message('Alpha -> Beta -> Alpha'), 13]]);
    }

    public function testAModulesDependenciesAreSearchedInNameOrder(): void
    {
        $this->analyse(
            [self::fixture('two-ways-back')],
            [
                [self::message('Gamma -> Alpha -> Gamma'), 14],
                [self::message('Alpha -> Beta -> Alpha'), 24],
            ],
        );
    }

    public function testAnEdgeSeenInTwoFilesReportsTheSiteInTheFileFirstByName(): void
    {
        $this->analyse(
            [self::fixture('later-file'), self::fixture('earlier-file')],
            [[self::message('Alpha -> Beta -> Alpha'), 23]],
        );
    }

    private static function fixture(string $name): string
    {
        return __DIR__ . '/data/service-module-cycle/' . $name . '.php';
    }

    private static function message(string $cycle): string
    {
        return sprintf(
            'Service modules must not depend on each other in a cycle: %s. '
            . 'Move the class that closes it into the module that owns it, '
            . 'or let the lower module own an interface the higher one implements (docs/architecture.md §9).',
            $cycle,
        );
    }
}
