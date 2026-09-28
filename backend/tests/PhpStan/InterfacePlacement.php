<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * An application interface ends in Interface and sits in a folder named after it, beside its same-module
 * implementations; an implementation in another module stays there (#1202). In Factory/, Model/ and Exception/ the
 * role goes before Interface; RoleFolderInterfaces places the first two, and an Exception/ one stays flat.
 */
final readonly class InterfacePlacement implements ServiceRoleChecker
{
    private const array SUFFIXED_ROLES = [
        ServiceRoleNames::FACTORY, ServiceRoleNames::MODEL, ServiceRoleNames::EXCEPTION,
    ];

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (ServiceRoleNames::isServiceOrHttp($class->name())) {
                $violations = [...$violations, ...self::violationsOf($map, $class)];
            }
        }

        return $violations;
    }

    /** @return list<ServiceRoleViolation> */
    private static function violationsOf(ServiceRoleMap $map, ServiceRoleClass $class): array
    {
        if (!$class->isInterface()) {
            return self::memberViolations($map, $class);
        }
        $role = $class->role();
        if (null !== $role && \in_array($role, self::SUFFIXED_ROLES, true)) {
            return self::roleInterfaceViolations($class, $role);
        }

        return self::interfaceViolations($map, $class);
    }

    /** @return list<ServiceRoleViolation> */
    private static function interfaceViolations(ServiceRoleMap $map, ServiceRoleClass $interface): array
    {
        $home = $map->interfaceHome($interface->name());
        $violations = [];
        if (!str_ends_with($interface->shortName(), 'Interface')) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::InterfaceName,
                $interface,
                'is an interface, so its name ends in Interface',
                $home,
            );
        }
        if ($map->interfaceFolder($interface->name()) !== $interface->namespace()) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::InterfaceFolder,
                $interface,
                'sits outside the folder named after it',
                $home,
            );
        }

        return $violations;
    }

    /** @return list<ServiceRoleViolation> */
    private static function roleInterfaceViolations(ServiceRoleClass $interface, string $role): array
    {
        $suffix = $role . 'Interface';
        if (str_ends_with($interface->shortName(), $suffix)) {
            return [];
        }
        $base = ServiceRoleNames::withoutSuffix($interface->shortName(), 'Interface');

        return [new ServiceRoleViolation(
            ServiceRoleCheck::InterfaceName,
            $interface,
            sprintf('is an interface in %s/, so its name ends in %s', $role, $suffix),
            $interface->namespace() . '\\' . $base . $suffix,
        )];
    }

    /** @return list<ServiceRoleViolation> */
    private static function memberViolations(ServiceRoleMap $map, ServiceRoleClass $class): array
    {
        if (null !== $class->role()) {
            return [];
        }
        $interfaces = $map->sameModuleInterfaces($class);
        if ([] === $interfaces) {
            return self::strangerViolations($map, $class);
        }
        $folders = array_map($map->interfaceFolder(...), $interfaces);
        if (\in_array($class->namespace(), $folders, true)) {
            return [];
        }

        return [new ServiceRoleViolation(
            ServiceRoleCheck::InterfaceFolder,
            $class,
            sprintf('implements %s, so it sits in that interface\'s folder', $interfaces[0]),
            $folders[0] . '\\' . $class->shortName(),
        )];
    }

    /** @return list<ServiceRoleViolation> */
    private static function strangerViolations(ServiceRoleMap $map, ServiceRoleClass $class): array
    {
        $folderInterface = $map->folderInterfaceOf($class->namespace());
        if (null === $folderInterface) {
            return [];
        }

        return [new ServiceRoleViolation(
            ServiceRoleCheck::InterfaceFolder,
            $class,
            sprintf('sits in the folder of %s but does not implement it', $folderInterface),
            ServiceRoleNames::namespaceOf($class->namespace()) . '\\' . $class->shortName(),
        )];
    }
}
