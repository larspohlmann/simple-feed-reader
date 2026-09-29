<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Reflection\ClassReflection;

/** In App\EventListener, or declared a Symfony or Doctrine listener by attribute, or a subscriber (#1202). */
final class EventListenerDeclarations
{
    private const string LISTENER_ATTRIBUTE = 'Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener';

    private const array CLASS_LISTENER_ATTRIBUTES = [
        self::LISTENER_ATTRIBUTE,
        'Doctrine\\Bundle\\DoctrineBundle\\Attribute\\AsDoctrineListener',
        'Doctrine\\Bundle\\DoctrineBundle\\Attribute\\AsEntityListener',
    ];

    private const string SUBSCRIBER_INTERFACE = 'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface';

    private function __construct()
    {
    }

    public static function isEventListener(ClassReflection $reflection): bool
    {
        return ServiceRoleNames::isListener($reflection->getName())
            || $reflection->implementsInterface(self::SUBSCRIBER_INTERFACE)
            || self::declaresListenerAttribute($reflection);
    }

    private static function declaresListenerAttribute(ClassReflection $reflection): bool
    {
        $native = $reflection->getNativeReflection();
        $onClass = array_any(
            self::CLASS_LISTENER_ATTRIBUTES,
            static fn (string $attribute): bool => [] !== $native->getAttributes($attribute),
        );

        return $onClass || array_any(
            $native->getMethods(),
            static fn (\ReflectionMethod $method): bool => [] !== $method->getAttributes(self::LISTENER_ATTRIBUTE),
        );
    }
}
