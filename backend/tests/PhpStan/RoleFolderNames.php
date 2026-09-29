<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * Factory/ and Model/ go together with their suffix, both ways (#1202). Enums in Model/ keep plain names, and an
 * interface inside its role folder is InterfacePlacement's to name.
 */
final readonly class RoleFolderNames implements ServiceRoleChecker
{
    private const array SUFFIXES = [ServiceRoleNames::FACTORY => 'Factory', ServiceRoleNames::MODEL => 'Model'];

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (!ServiceRoleNames::isServiceOrHttp($class->name()) || $class->isEnum()) {
                continue;
            }
            foreach (self::SUFFIXES as $role => $suffix) {
                $violation = self::violationFor($class, $role, $suffix);
                if (null !== $violation) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private static function violationFor(ServiceRoleClass $class, string $role, string $suffix): ?ServiceRoleViolation
    {
        $expected = $class->isInterface() ? $suffix . 'Interface' : $suffix;
        $inFolder = $role === $class->role();
        if ($inFolder && $class->isInterface()) {
            return null;
        }
        $named = str_ends_with($class->shortName(), $expected);
        if ($inFolder === $named) {
            return null;
        }
        $check = ServiceRoleNames::FACTORY === $role ? ServiceRoleCheck::FactoryName : ServiceRoleCheck::ModelName;
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
