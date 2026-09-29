<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** A Support/ helper is final, never instantiated and stateless: a private constructor, no static property (#1202). */
final readonly class SupportShapes implements ServiceRoleChecker
{
    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->applicationClasses() as $class) {
            if (self::isSupportHelper($class)) {
                $violations = [...$violations, ...self::shapeViolations($class)];
            }
        }

        return $violations;
    }

    private static function isSupportHelper(ServiceRoleClass $class): bool
    {
        return ServiceRoleNames::SUPPORT === $class->role()
            && $class->isStaticOnly();
    }

    /** @return list<ServiceRoleViolation> */
    private static function shapeViolations(ServiceRoleClass $helper): array
    {
        return array_map(
            static fn (string $problem): ServiceRoleViolation => new ServiceRoleViolation(
                ServiceRoleCheck::SupportShape,
                $helper,
                $problem,
            ),
            self::problemsOf($helper),
        );
    }

    /** @return list<string> */
    private static function problemsOf(ServiceRoleClass $helper): array
    {
        $problems = [];
        if (!$helper->isFinal()) {
            $problems[] = 'is a helper, so it is final';
        }
        if (!$helper->hasPrivateConstructor()) {
            $problems[] = 'is a helper, so it is never instantiated; declare a private constructor';
        }
        if ([] !== $helper->staticProperties()) {
            $problems[] = sprintf(
                'keeps state in static $%s; a helper holds none, so the state goes to a service',
                implode(', $', $helper->staticProperties()),
            );
        }

        return $problems;
    }
}
