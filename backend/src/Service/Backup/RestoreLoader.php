<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\FeedRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pass 2 of the restore: the second read of the same bytes, this time writing
 * rows. It assumes AccountReset has just run — nothing here removes anything.
 *
 * The service itself is stateless and shared. All the per-run state a load
 * needs lives on the RestoreLoadPass built here and discarded with the call,
 * which is the shape phptramp asks for instead of threading it through a
 * chain of parameters.
 */
final readonly class RestoreLoader
{
    public function __construct(
        private EntityManagerInterface $em,
        private BackupReader $reader,
        private FeedRepository $feeds,
    ) {
    }

    public function load(User $user, string $gzipBytes): RestoreResult
    {
        return (new RestoreLoadPass($this->em, $this->feeds))->run($user, $this->reader->read($gzipBytes));
    }
}
