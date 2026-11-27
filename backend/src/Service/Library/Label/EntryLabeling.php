<?php

declare(strict_types=1);

namespace App\Service\Library\Label;

use App\Entity\Entry;
use App\Entity\Label;
use App\Entity\User;
use App\Repository\EntryLabelRepository;

final readonly class EntryLabeling
{
    public function __construct(
        private EntryLabelRepository $entryLabels,
        private LabelEditor $labelEditor,
    ) {
    }

    public function attach(Label $label, Entry $entry): void
    {
        $this->entryLabels->addMissing([$entry->requireId() => [$label->requireId()]]);
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
