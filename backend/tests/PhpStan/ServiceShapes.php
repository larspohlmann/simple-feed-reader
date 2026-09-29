<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/**
 * A service is final readonly and takes only collaborators. One that keeps state says how that state ends: the
 * Messenger worker resets it between messages (ResetInterface), or #[ProcessLifetimeState] says why it may not (#1202).
 */
final readonly class ServiceShapes implements ServiceRoleChecker
{
    private const array SERVICE_ROLES = [null, ServiceRoleNames::FACTORY, ServiceRoleNames::HANDLER];

    private const array UNSUPPLIED_ROLES = [ServiceRoleNames::MODEL, ServiceRoleNames::PASS, ServiceRoleNames::DTO];

    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (self::isService($map, $class)) {
                $violations = [...$violations, ...self::shapeViolations($class), ...self::stateViolations($class)];
            }
        }

        return $violations;
    }

    private static function isService(ServiceRoleMap $map, ServiceRoleClass $class): bool
    {
        if (!ServiceRoleNames::isService($class->name()) || !$class->isPlainClass() || $class->isStaticOnly()) {
            return false;
        }
        if (!\in_array($class->role(), self::SERVICE_ROLES, true)) {
            return false;
        }

        return !$map->isPerCall($class)
            || ServiceRoleNames::FACTORY === $class->role()
            || [] !== $map->sameModuleInterfaces($class);
    }

    /** @return list<ServiceRoleViolation> */
    private static function shapeViolations(ServiceRoleClass $service): array
    {
        $violations = [];
        if (!$service->isAbstract() && !$service->reflection->isFinalByKeyword()) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::RootService,
                $service,
                'is a service, so it is final',
            );
        }
        if (!$service->reflection->isReadOnly() && !$service->isStateful() && $service->mayBeReadonly()) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::RootService,
                $service,
                'keeps no state, so it is readonly',
            );
        }
        foreach ($service->suppliedConstructorTypes() as $type) {
            $role = ServiceRoleNames::roleOfClass($type);
            if (\in_array($role, self::UNSUPPLIED_ROLES, true)) {
                $violations[] = new ServiceRoleViolation(
                    ServiceRoleCheck::RootService,
                    $service,
                    sprintf('takes %s, which the container cannot supply', $type),
                );
            }
        }

        return $violations;
    }

    /** @return list<ServiceRoleViolation> */
    private static function stateViolations(ServiceRoleClass $service): array
    {
        if (!$service->isStateful() || $service->resetsItsState()) {
            return [];
        }

        return [new ServiceRoleViolation(
            ServiceRoleCheck::StatefulService,
            $service,
            sprintf(
                'keeps state in $%s; implement ResetInterface or add #[ProcessLifetimeState]',
                implode(', $', $service->mutableProperties()),
            ),
        )];
    }
}
