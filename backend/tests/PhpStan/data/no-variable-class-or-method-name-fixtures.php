<?php

declare(strict_types=1);

// Fixtures for NoVariableClassOrMethodNameRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Fixtures\VariableClassOrMethodName {
    final class Target
    {
        public const string NAME = 'target';

        public static int $count = 0;

        public static function create(): self
        {
            return new self();
        }

        public function run(): void
        {
        }
    }

    /** @param class-string<Target> $class */
    function variableNames(Target $target, string $class, string $method, string $name): void
    {
        $$name = 1;
        $target->$method();
        $target?->{$method}();
        Target::$method();
        $class::create();
        new $class();
        $class::NAME;
        $class::$count;
        $target instanceof $class;
        Target::{$name};
        Target::$$name;
    }

    function literalNames(Target $target, \Closure $callback): void
    {
        $target->run();
        $target?->run();
        $target->run(...);
        Target::create();
        new Target();
        new class () {
        };
        Target::NAME;
        Target::$count;
        $target instanceof Target;
        $target::class;
        $callback();
    }
}

namespace App\Tests\Fixtures\VariableClassOrMethodName {
    function testsMayNameThroughAVariable(object $subject, string $method): void
    {
        $subject->$method();
    }
}
