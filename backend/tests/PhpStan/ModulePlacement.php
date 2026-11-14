<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** Every service class sits in a module folder, `App\Service\<Group>\<Module>`, below one of the declared groups. */
final readonly class ModulePlacement implements ServiceRoleChecker
{
    /** @param list<string> $groups */
    public function __construct(private array $groups)
    {
    }

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (ServiceRoleNames::isService($class->name()) && !$this->sitsInAModule($class)) {
                $violations[] = new ServiceRoleViolation(
                    ServiceRoleCheck::ModuleHome,
                    $class,
                    'sits outside a module folder',
                    sprintf('App\Service\<Group>\<Module>, with <Group> one of %s', implode(', ', $this->groups)),
                );
            }
        }

        return $violations;
    }

    private function sitsInAModule(ServiceRoleClass $class): bool
    {
        $belowService = substr($class->namespace(), \strlen(ServiceModules::SERVICE_NAMESPACE));
        [$group, $module] = explode('\\', $belowService, 3) + ['', ''];

        return \in_array($group, $this->groups, true) && '' !== $module;
    }
}
