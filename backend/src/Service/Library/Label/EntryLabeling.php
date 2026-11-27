<?php

declare(strict_types=1);

namespace App\Service\Library\Label;

use App\Entity\Entry;
use App\Entity\EntryLabel;
use App\Entity\Label;
use App\Entity\User;
use App\Repository\EntryLabelRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EntryLabeling
{
    public function __construct(
        private EntryLabelRepository $entryLabels,
        private LabelEditor $labelEditor,
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

    /**
     * Resolves each name to the user's label of that name, case-insensitively, creating the ones missing.
     *
     * @param array<int, list<string>> $labelNamesByEntryId
     */
    public function attachByName(User $user, array $labelNamesByEntryId): void
    {
        $labelIdsByName = [];
        $labelIdsByEntryId = [];
        foreach ($labelNamesByEntryId as $entryId => $names) {
            foreach ($names as $name) {
                $key = mb_strtolower($name);
                $labelIdsByName[$key] ??= $this->labelEditor->ensureNamed($user, $name)->requireId();
                $labelIdsByEntryId[$entryId][] = $labelIdsByName[$key];
            }
        }

        $this->entryLabels->addMissing($labelIdsByEntryId);
    }

    public function detach(Label $label, Entry $entry): void
    {
        $this->entryLabels->removeFor($label, $entry);
    }
}
