<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** A Support/ helper is final, never instantiated and stateless: a private constructor, no static property (#1202). */
final readonly class SupportShapes implements ServiceRoleChecker
{
    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            if (self::isSupportHelper($class)) {
                $violations = [...$violations, ...self::shapeViolations($class)];
            }
        }

        return $violations;
    }

    private static function isSupportHelper(ServiceRoleClass $class): bool
    {
        return ServiceRoleNames::isServiceOrHttp($class->name())
            && ServiceRoleNames::SUPPORT === $class->role()
            && $class->isStaticOnly();
    }

    /** @return list<ServiceRoleViolation> */
    private static function shapeViolations(ServiceRoleClass $helper): array
    {
        $violations = [];
        if (!$helper->isFinal()) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::SupportShape,
                $helper,
                'is a helper, so it is final',
            );
        }
        if (!$helper->hasPrivateConstructor()) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::SupportShape,
                $helper,
                'is a helper, so it is never instantiated; declare a private constructor',
            );
        }
        if ([] !== $helper->staticProperties()) {
            $violations[] = new ServiceRoleViolation(
                ServiceRoleCheck::SupportShape,
                $helper,
                sprintf(
                    'keeps state in static $%s; a helper holds none, so the state goes to a service',
                    implode(', $', $helper->staticProperties()),
                ),
            );
        }

        return $violations;
    }
}
