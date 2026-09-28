<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * A service module's area root holds stateless services. Enums and data built per call are models, per-call objects
 * that hold state or collaborators go to Pass/, static-only helpers go to Support/ (#1202).
 */
final readonly class RootPlacement implements ServiceRoleChecker
{
    private const array SETTLED_ROLES = [
        ServiceRoleNames::EXCEPTION, ServiceRoleNames::MESSAGE, ServiceRoleNames::MODEL, ServiceRoleNames::PASS,
    ];

    private const string PER_CALL_NAMES = '/(Pass|Context|Tally)$/';

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (!ServiceRoleNames::isService($class->name()) || $class->isInterface()) {
                continue;
            }
            $violation = self::placementViolation($map, $class);
            if (null !== $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private static function placementViolation(ServiceRoleMap $map, ServiceRoleClass $class): ?ServiceRoleViolation
    {
        $role = $class->role();
        if (ServiceRoleNames::SUPPORT === $role) {
            return $class->isStaticOnly() ? null : new ServiceRoleViolation(
                ServiceRoleCheck::SupportHome,
                $class,
                'sits in Support/ but is not static-only',
            );
        }
        if (\in_array($role, self::SETTLED_ROLES, true)) {
            return null;
        }
        if ($class->isStaticOnly()) {
            return new ServiceRoleViolation(ServiceRoleCheck::SupportHome, $class, 'is static-only', self::homeIn(
                $class,
                ServiceRoleNames::SUPPORT,
            ));
        }
        if (ServiceRoleNames::DTO === $role) {
            return null;
        }
        if ($class->isEnum()) {
            return new ServiceRoleViolation(ServiceRoleCheck::ModelHome, $class, 'is an enum', self::homeIn(
                $class,
                ServiceRoleNames::MODEL,
            ));
        }

        return self::isPlacedByItsInterfaceOrName($map, $class) ? null : self::perCallViolation($map, $class);
    }

    private static function isPlacedByItsInterfaceOrName(ServiceRoleMap $map, ServiceRoleClass $class): bool
    {
        return ServiceRoleNames::FACTORY === $class->role()
            || str_ends_with($class->shortName(), 'Factory')
            || [] !== $map->sameModuleInterfaces($class);
    }

    private static function perCallViolation(ServiceRoleMap $map, ServiceRoleClass $class): ?ServiceRoleViolation
    {
        if (!$map->isPerCall($class)) {
            return null;
        }
        if (self::isPass($map, $class)) {
            return new ServiceRoleViolation(ServiceRoleCheck::PassHome, $class, 'is built per call', self::homeIn(
                $class,
                ServiceRoleNames::PASS,
            ));
        }
        $name = str_ends_with($class->shortName(), 'Model') ? $class->shortName() : $class->shortName() . 'Model';

        return new ServiceRoleViolation(
            ServiceRoleCheck::ModelHome,
            $class,
            'is data built per call',
            $class->area() . '\\' . ServiceRoleNames::MODEL . '\\' . $name,
        );
    }

    private static function isPass(ServiceRoleMap $map, ServiceRoleClass $class): bool
    {
        if ($class->isStateful() || 1 === preg_match(self::PER_CALL_NAMES, $class->shortName())) {
            return true;
        }
        foreach ($class->suppliedConstructorTypes() as $type) {
            if ($map->isCollaborator($type)) {
                return true;
            }
        }

        return false;
    }

    private static function homeIn(ServiceRoleClass $class, string $role): string
    {
        return $class->area() . '\\' . $role . '\\' . $class->shortName();
    }
}
