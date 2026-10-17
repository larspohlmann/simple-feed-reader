<?php

declare(strict_types=1);

namespace App\Service\Backup\Factory;

use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\LinkCardRepository;
use App\Repository\SavedEntryOriginRepository;
use App\Service\Backup\BackupLines;
use App\Service\Backup\Model\BackupProvenanceModel;
use App\Service\Backup\Pass\BackupPartWalk;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BackupPartWalkFactory
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryRepository $entries,
        private EntryStateRepository $entryStates,
        private LinkCardRepository $linkCards,
        private SavedEntryOriginRepository $savedOrigins,
        private BackupLines $lines,
    ) {
    }

    public function create(BackupProvenanceModel $provenance, int $userId): BackupPartWalk
    {
        return new BackupPartWalk(
            $this->entityManager,
            $this->entries,
            $this->entryStates,
            $this->linkCards,
            $this->savedOrigins,
            $this->lines,
            $provenance,
            $userId,
        );
    }
}
