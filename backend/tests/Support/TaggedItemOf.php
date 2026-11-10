<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

final class TaggedItemOf
{
    /** @param class-string $class */
    public static function index(string $class): string
    {
        return self::attributeOf($class)->index
            ?? throw new \LogicException(sprintf('%s has no tagged-item index', $class));
    }

    /** @param class-string $class */
    public static function priority(string $class): int
    {
        return self::attributeOf($class)->priority ?? 0;
    }

    /** @param class-string $class */
    private static function attributeOf(string $class): AsTaggedItem
    {
        $attributes = (new \ReflectionClass($class))->getAttributes(AsTaggedItem::class);
        if ([] === $attributes) {
            throw new \LogicException(sprintf('%s carries no #[AsTaggedItem]', $class));
        }

        return $attributes[0]->newInstance();
    }
}
