<?php

declare(strict_types=1);

namespace App\Tests\Support;

trait AssignsEntityIds
{
    private static function assignId(object $entity, int $id): void
    {
        (new \ReflectionProperty($entity::class, 'id'))->setValue($entity, $id);
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private static function withId(object $entity, int $id): object
    {
        self::assignId($entity, $id);

        return $entity;
    }
}
