<?php

declare(strict_types=1);

namespace App\Service\Ordering;

use App\Entity\Positioned;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PositionReorderer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<int>              $orderedIds
     * @param array<int, Positioned> $byId
     */
    public function reorder(array $orderedIds, array $byId): void
    {
        foreach ($orderedIds as $index => $id) {
            $byId[$id]->setPosition($index);
        }
        $this->entityManager->flush();
    }
}
