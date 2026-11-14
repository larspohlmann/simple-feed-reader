<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Every service class sits in a module folder, `App\Service\<Group>\<Module>`, never loose in a group or the root. */
final readonly class ModulePlacement implements ServiceRoleChecker
{
    private const int MODULE_DEPTH = 2;

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (ServiceRoleNames::isService($class->name()) && !self::sitsInAModule($class)) {
                $violations[] = new ServiceRoleViolation(
                    ServiceRoleCheck::ModuleHome,
                    $class,
                    'sits outside a module folder',
                    'a module folder, App\Service\<Group>\<Module>',
                );
            }
        }

        return $violations;
    }

    private static function sitsInAModule(ServiceRoleClass $class): bool
    {
        $belowService = substr($class->namespace(), \strlen(ServiceModules::SERVICE_NAMESPACE) - 1);

        return substr_count($belowService, '\\') >= self::MODULE_DEPTH;
    }
}
