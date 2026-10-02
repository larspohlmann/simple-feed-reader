<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ServiceModuleCycleRule> */
final class ServiceModuleCycleRuleTest extends RuleTestCase
{
    private const array SUB_MODULES = ['Recommendation\\Llm', 'Atlas\\Maps', 'Atlas\\Maps\\Tiles'];

    protected function getRule(): Rule
    {
        return new ServiceModuleCycleRule(new ServiceModules(self::SUB_MODULES));
    }

    protected function getCollectors(): array
    {
        return [new ServiceModuleDependencyCollector(new NodeFinder(), new ServiceModules(self::SUB_MODULES))];
    }

    public function testATwoModuleCycleIsReportedOnceWhereItCloses(): void
    {
        $this->analyse(
            [self::fixture('two-module-cycle')],
            [[self::message('Alpha -> Beta (two-module-cycle.php:11) -> Alpha (two-module-cycle.php:26)'), 26]],
        );
    }

    public function testAThreeModuleCycleNamesEveryModuleOnItsPath(): void
    {
        $this->analyse(
            [self::fixture('three-module-cycle')],
            [[self::message(
                'Alpha -> Beta (three-module-cycle.php:9) -> Gamma (three-module-cycle.php:21) '
                . '-> Alpha (three-module-cycle.php:36)',
            ), 36]],
        );
    }

    public function testAnAcyclicGraphIsClean(): void
    {
        $this->analyse([self::fixture('acyclic')], []);
    }

    public function testAClassLooseInTheServiceRootIsAModuleOfItsOwn(): void
    {
        $this->analyse(
            [self::fixture('LooseService')],
            [[self::message('Alpha -> LooseService (LooseService.php:20) -> Alpha (LooseService.php:9)'), 9]],
        );
    }

    public function testEveryDistinctCycleIsReported(): void
    {
        $this->analyse(
            [self::fixture('two-cycles')],
            [
                [self::message('Alpha -> Beta (two-cycles.php:13) -> Alpha (two-cycles.php:23)'), 23],
                [self::message('Delta -> Gamma (two-cycles.php:43) -> Delta (two-cycles.php:33)'), 33],
            ],
        );
    }

    public function testModulesAreWalkedInNameOrder(): void
    {
        $this->analyse(
            [self::fixture('beta-first')],
            [[self::message('Alpha -> Beta (beta-first.php:23) -> Alpha (beta-first.php:13)'), 13]],
        );
    }

    public function testAModulesDependenciesAreSearchedInNameOrder(): void
    {
        $this->analyse(
            [self::fixture('two-ways-back')],
            [
                [self::message('Gamma -> Alpha (two-ways-back.php:34) -> Gamma (two-ways-back.php:14)'), 14],
                [self::message('Alpha -> Beta (two-ways-back.php:14) -> Alpha (two-ways-back.php:24)'), 24],
            ],
        );
    }

    public function testAnEdgeSeenInTwoFilesReportsTheSiteInTheFileFirstByName(): void
    {
        $this->analyse(
            [self::fixture('later-file'), self::fixture('earlier-file')],
            [[self::message('Alpha -> Beta (earlier-file.php:13) -> Alpha (earlier-file.php:23)'), 23]],
        );
    }

    public function testTheLlmSubModuleMayReachRecommendationDirectlyAndThroughAnotherModule(): void
    {
        $this->analyse([self::fixture('recommendation-sub-module-acyclic')], []);
    }

    public function testACycleThroughASubModuleIsReportedWhereItsParentNamesIt(): void
    {
        $this->analyse(
            [self::fixture('recommendation-sub-module-cycle')],
            [[self::message(
                'Recommendation -> Recommendation\Llm (recommendation-sub-module-cycle.php:25) '
                . '-> Recommendation (recommendation-sub-module-cycle.php:31)',
            ), 25]],
        );
    }

    public function testAnySubModuleOfAnyParentIsReportedWhereItsParentNamesIt(): void
    {
        $this->analyse(
            [self::fixture('made-up-parent-sub-module-cycle')],
            [[self::message(
                'Atlas -> Atlas\Maps (made-up-parent-sub-module-cycle.php:13) '
                . '-> Atlas (made-up-parent-sub-module-cycle.php:19)',
            ), 13]],
        );
    }

    public function testTheLongestDeclaredSubModuleWinsSoASubModuleMayHaveOneOfItsOwn(): void
    {
        $this->analyse(
            [self::fixture('nested-sub-module-cycle')],
            [[self::message(
                'Atlas\Maps -> Atlas\Maps\Tiles (nested-sub-module-cycle.php:13) '
                . '-> Atlas\Maps (nested-sub-module-cycle.php:19)',
            ), 13]],
        );
    }

    public function testAPeerNamingASubModuleIsReportedWhereTheCycleCloses(): void
    {
        $this->analyse(
            [self::fixture('peer-names-sub-module-cycle')],
            [[self::message(
                'Digest -> Recommendation\Llm (peer-names-sub-module-cycle.php:9) '
                . '-> Digest (peer-names-sub-module-cycle.php:20)',
            ), 20]],
        );
    }

    public function testASiblingWhoseNameStartsLikeTheSubModuleStaysInRecommendation(): void
    {
        $this->analyse([self::fixture('recommendation-sibling-of-sub-module')], []);
    }

    public function testAnAliasImportOfTheSubModulesNamespaceNamesTheSubModule(): void
    {
        $this->analyse(
            [self::fixture('recommendation-sub-module-alias')],
            [[self::message(
                'Recommendation\Llm -> Schedule (recommendation-sub-module-alias.php:21) '
                . '-> Recommendation\Llm (recommendation-sub-module-alias.php:9)',
            ), 9]],
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
            . 'Break it at the reported dependency: move the class it names into the module that owns it, '
            . 'or let the lower module own an interface the higher one implements (docs/architecture.md §9).',
            $cycle,
        );
    }
}
