<?php

declare(strict_types=1);

namespace App\Service\Foundation\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** Reopens the default entity manager after a failed transaction closed it, so later work can still flush. */
final readonly class ManagerReopener
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function ensureManagerOpen(): void
    {
        $manager = $this->registry->getManager();
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager();
        }
    }
}
