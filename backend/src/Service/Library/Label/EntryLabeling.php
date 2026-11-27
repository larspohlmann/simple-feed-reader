<?php

declare(strict_types=1);

namespace App\Service\Library\Label;

use App\Entity\Entry;
use App\Entity\EntryLabel;
use App\Entity\Label;
use App\Repository\EntryLabelRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EntryLabeling
{
    public function __construct(
        private EntryLabelRepository $entryLabels,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function attach(Label $label, Entry $entry): void
    {
        if ($this->entryLabels->exists($label, $entry)) {
            return;
        }

        $this->entityManager->persist(new EntryLabel($label, $entry));

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return;
        }
    }

    public function detach(Label $label, Entry $entry): void
    {
        $this->entryLabels->removeFor($label, $entry);
    }
}
