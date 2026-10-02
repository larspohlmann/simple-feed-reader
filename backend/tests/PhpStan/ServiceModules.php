<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * Which App\Service module a class belongs to (docs/architecture.md §9): its first segment below App\Service, unless
 * it sits in a declared sub-module (phpstan.dist.neon `serviceSubModules`), which is a module of its own.
 */
final readonly class ServiceModules
{
    public const string SERVICE_NAMESPACE = 'App\\Service\\';

    /** @var list<string> */
    private array $subModules;

    /** @param list<string> $subModules relative to App\Service, such as `Parent\Directory` */
    public function __construct(array $subModules)
    {
        usort($subModules, static fn (string $left, string $right): int => \strlen($right) <=> \strlen($left));
        $this->subModules = $subModules;
    }

    /** The longest declared sub-module the class sits in, else its first segment (a loose class's own name). */
    public function of(string $className): string
    {
        $relativeName = substr($className, \strlen(self::SERVICE_NAMESPACE));

        return array_find(
            $this->subModules,
            static fn (string $subModule): bool => $relativeName === $subModule
                || str_starts_with($relativeName, $subModule . '\\'),
        ) ?? explode('\\', $relativeName)[0];
    }

    /** `App\Service\<Module>` for a service, a sub-module included, `App\<Layer>` for anything else. */
    public function namespaceOf(string $className): string
    {
        if (ServiceRoleNames::isService($className)) {
            return self::SERVICE_NAMESPACE . $this->of($className);
        }

        return implode('\\', \array_slice(explode('\\', $className), 0, 2));
    }

    /** Whether $module is a declared sub-module below $parent; a parent naming it closes any cycle through it at that import. */
    public function isSubModuleOf(string $module, string $parent): bool
    {
        return \in_array($module, $this->subModules, true) && str_starts_with($module, $parent . '\\');
    }
}
