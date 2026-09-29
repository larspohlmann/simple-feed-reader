<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * Factory/ and Model/ go together with their suffix, both ways (#1202). Enums in Model/ keep plain names, and an
 * interface inside its role folder is InterfacePlacement's to name.
 */
final readonly class RoleFolderNames implements ServiceRoleChecker
{
    private const array ROLES = [
        ServiceRoleNames::FACTORY => ServiceRoleCheck::FactoryName,
        ServiceRoleNames::MODEL => ServiceRoleCheck::ModelName,
    ];

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (!ServiceRoleNames::isServiceOrHttp($class->name()) || $class->isEnum()) {
                continue;
            }
            foreach (self::ROLES as $role => $check) {
                $violation = self::violationFor($class, $role, $check);
                if (null !== $violation) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private static function violationFor(
        ServiceRoleClass $class,
        string $role,
        ServiceRoleCheck $check,
    ): ?ServiceRoleViolation {
        $expected = $class->isInterface() ? $role . 'Interface' : $role;
        $inFolder = $role === $class->role();
        if ($inFolder && $class->isInterface()) {
            return null;
        }
        $named = str_ends_with($class->shortName(), $expected);
        if ($inFolder === $named) {
            return null;
        }
        if ($inFolder) {
            return new ServiceRoleViolation(
                $check,
                $class,
                sprintf('sits in %s/, so its name ends in %s', $role, $expected),
            );
        }

        return new ServiceRoleViolation(
            $check,
            $class,
            sprintf('ends in %s, so it sits in %s/', $expected, $role),
            $class->roleHome($role),
        );
    }
}
