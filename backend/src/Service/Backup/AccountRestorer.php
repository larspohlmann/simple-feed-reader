<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Service\Account\AccountReset;
use App\Service\Backup\Model\RestoreResultModel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Validates and counts the real bytes, refuses a file that does not fit, and only then wipes and loads; both passes
 * read the same in-memory gzip. Not transactional on purpose: docs/backup.md#4-when-a-restore-fails.
 */
final readonly class AccountRestorer
{
    private const string CONFIRMATION = 'REPLACE';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BackupInspector $inspector,
        private BackupFitCheck $fitCheck,
        private AccountReset $accountReset,
        private RestoreLoader $loader,
    ) {
    }

    public function start(User $user, string $gzipBytes, ?string $confirmation): RestoreResultModel
    {
        if (self::CONFIRMATION !== $confirmation) {
            throw new ValidationException(['confirm' => ['Type REPLACE to confirm the restore.']]);
        }

        $inventory = $this->inspector->inspect($gzipBytes);
        $this->fitCheck->assertFits($inventory, $user);
        $userId = $user->requireId();
        $this->accountReset->reset($user);

        return $this->loader->load($this->refreshed($userId), $gzipBytes);
    }

    /**
     * AccountReset ends with clear(), so the caller's User is detached by the
     * time the load starts — and a detached entity cannot anchor the tags,
     * subscriptions and states the loader is about to create.
     */
    private function refreshed(int $userId): User
    {
        return $this->entityManager->find(User::class, $userId)
            ?? throw new \LogicException('The account disappeared during its own restore.');
    }
}
