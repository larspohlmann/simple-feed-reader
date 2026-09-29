<?php

declare(strict_types=1);

namespace App\Service\Ordering;

use App\Entity\PositionedInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PositionReorderer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<int>              $orderedIds
     * @param array<int, PositionedInterface> $byId
     */
    public function reorder(array $orderedIds, array $byId): void
    {
        foreach ($orderedIds as $index => $id) {
            $byId[$id]->setPosition($index);
        }
        $this->entityManager->flush();
    }

    /**
     * @param list<int>                          $orderedIds
     * @param \Closure(int): PositionedInterface $find throws for an id it does not know
     */
    public function reorderFound(array $orderedIds, \Closure $find): void
    {
        $byId = [];
        foreach ($orderedIds as $id) {
            $byId[$id] = $find($id);
        }
        $this->reorder($orderedIds, $byId);
    }
}
