<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPUnit\Framework\TestCase;

final class ServiceModulesTest extends TestCase
{
    public function testADeclaredSubModuleBelongsToItsParent(): void
    {
        $modules = new ServiceModules(['Curation\\Recommendation\\Llm']);

        self::assertTrue($modules->isSubModuleOf('Curation\\Recommendation\\Llm', 'Curation\\Recommendation'));
    }

    public function testAPathBelowAParentThatNothingDeclaresIsNoSubModule(): void
    {
        $modules = new ServiceModules(['Curation\\Recommendation\\Llm']);

        self::assertFalse($modules->isSubModuleOf('Curation\\Recommendation\\Pool', 'Curation\\Recommendation'));
    }

    public function testAParentIsNotItsOwnSubModule(): void
    {
        $modules = new ServiceModules(['Curation\\Recommendation\\Llm']);

        self::assertFalse($modules->isSubModuleOf('Curation\\Recommendation', 'Curation\\Recommendation'));
    }
}
