<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\FeedRepository;
use App\Service\Backup\Factory\RestoredFoundationFactory;
use App\Service\Backup\Model\RestoreResultModel;
use App\Service\Backup\Pass\RestoreLoadPass;
use App\Service\Search\SavedSearchSlug;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pass 2 of the restore: the second read of the same bytes, now writing rows. It assumes AccountReset has just run
 * and removes nothing; the per-run state lives on the RestoreLoadPass built for each call.
 */
final readonly class RestoreLoader
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BackupReader $reader,
        private FeedRepository $feeds,
        private SavedSearchSlug $savedSearchSlug,
        private RestoredFoundationFactory $foundationFactory,
    ) {
    }

    public function load(User $user, string $gzipBytes): RestoreResultModel
    {
        $pass = new RestoreLoadPass(
            $this->entityManager,
            $this->feeds,
            $this->savedSearchSlug,
            $this->foundationFactory,
        );

        return $pass->run($user, $this->reader->read($gzipBytes));
    }
}
