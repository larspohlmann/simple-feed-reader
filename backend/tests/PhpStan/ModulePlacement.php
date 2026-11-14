<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Every service class sits in a module folder, `App\Service\<Group>\<Module>`, below one of the declared groups. */
final readonly class ModulePlacement implements ServiceRoleChecker
{
    public function __construct(private ServiceModules $modules)
    {
    }

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (ServiceRoleNames::isService($class->name()) && !$this->modules->isInAModule($class->name())) {
                $violations[] = new ServiceRoleViolation(
                    ServiceRoleCheck::ModuleHome,
                    $class,
                    'sits outside a module folder',
                    'App\Service\<Group>\<Module>, with <Group> one of ' . implode(', ', $this->modules->groups),
                );
            }
        }

        return $violations;
    }
}
