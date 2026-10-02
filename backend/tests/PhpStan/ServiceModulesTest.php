<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPUnit\Framework\TestCase;

final class ServiceModulesTest extends TestCase
{
    public function testADeclaredSubModuleBelongsToItsParent(): void
    {
        $modules = new ServiceModules(['Recommendation\\Llm']);

        self::assertTrue($modules->isSubModuleOf('Recommendation\\Llm', 'Recommendation'));
    }

    public function testAPathBelowAParentThatNothingDeclaresIsNoSubModule(): void
    {
        $modules = new ServiceModules(['Recommendation\\Llm']);

        self::assertFalse($modules->isSubModuleOf('Recommendation\\Pool', 'Recommendation'));
    }

    public function testAParentIsNotItsOwnSubModule(): void
    {
        $modules = new ServiceModules(['Recommendation\\Llm']);

        self::assertFalse($modules->isSubModuleOf('Recommendation', 'Recommendation'));
    }
}
