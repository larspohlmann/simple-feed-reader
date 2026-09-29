<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Reflection\ClassReflection;

/** An event listener's name ends in Listener; a message handler is its message's name plus Handler (#1202). */
final readonly class MessagingNames implements ServiceRoleChecker
{
    public function violationsIn(ServiceRoleMap $map): array
    {
        $violations = [];
        foreach ($map->classes() as $class) {
            $violation = self::listenerViolation($class) ?? self::handlerViolation($class);
            if (null !== $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    private static function listenerViolation(ServiceRoleClass $class): ?ServiceRoleViolation
    {
        if (
            !$class->isPlainClass() || !EventListenerDeclarations::isEventListener($class->reflection)
            || str_ends_with($class->shortName(), 'Listener')
        ) {
            return null;
        }

        return new ServiceRoleViolation(
            ServiceRoleCheck::ListenerName,
            $class,
            'is an event listener, so its name ends in Listener',
            $class->name() . 'Listener',
        );
    }

    private static function handlerViolation(ServiceRoleClass $class): ?ServiceRoleViolation
    {
        $message = ServiceRoleNames::HANDLER === $class->role() ? self::invokedMessageOf($class->reflection) : null;
        if (null === $message) {
            return null;
        }
        $expected = ServiceRoleNames::shortNameOf($message) . 'Handler';
        if ($expected === $class->shortName()) {
            return null;
        }

        return new ServiceRoleViolation(
            ServiceRoleCheck::HandlerName,
            $class,
            sprintf('handles %s, so its name is %s', $message, $expected),
            $class->namespace() . '\\' . $expected,
        );
    }

    private static function invokedMessageOf(ClassReflection $reflection): ?string
    {
        if (!$reflection->hasNativeMethod('__invoke')) {
            return null;
        }
        $parameters = $reflection->getNativeMethod('__invoke')->getOnlyVariant()->getParameters();
        $classes = [] === $parameters ? [] : $parameters[0]->getType()->getObjectClassNames();

        return $classes[0] ?? null;
    }
}
