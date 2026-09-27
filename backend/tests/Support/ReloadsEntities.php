<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

trait ReloadsEntities
{
    /**
     * clear() first: without it the identity map hands back the object the test already holds.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function reload(object $entity): object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $identifier = $entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $entityManager->clear();

        $reloaded = $entityManager->find($entity::class, $identifier);
        self::assertInstanceOf($entity::class, $reloaded);

        return $reloaded;
    }
}
