<?php

declare(strict_types=1);

namespace App\Service\Operations\Backup\Factory;

use App\Repository\EntryRepository;
use App\Repository\EntryShareRepository;
use App\Repository\EntryStateRepository;
use App\Repository\LinkCardRepository;
use App\Repository\SavedEntryOriginRepository;
use App\Service\Operations\Backup\Model\BackupProvenanceModel;
use App\Service\Operations\Backup\Pass\BackupPartWalk;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BackupPartWalkFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryRepository $entries,
        private EntryStateRepository $entryStates,
        private EntryShareRepository $entryShares,
        private LinkCardRepository $linkCards,
        private SavedEntryOriginRepository $savedOrigins,
    ) {
    }

    public function create(BackupProvenanceModel $provenance, int $userId): BackupPartWalk
    {
        return new BackupPartWalk(
            $this->entityManager,
            $this->entries,
            $this->entryStates,
            $this->entryShares,
            $this->linkCards,
            $this->savedOrigins,
            $provenance,
            $userId,
        );
    }
}
