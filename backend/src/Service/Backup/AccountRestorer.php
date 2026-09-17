<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Service\Account\AccountReset;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The whole restore, in the only safe order: validate and count the real
 * bytes, refuse anything that does not fit, and only then wipe and load.
 * The two passes read the same in-memory gzip string, so the file that
 * passed the fit check is byte-for-byte the file that loads.
 *
 * Deliberately NOT transactional (spec §8): a crash mid-load leaves a wiped,
 * partly loaded account, and the remedy is re-running the same file — the
 * wipe is idempotent.
 */
final readonly class AccountRestorer
{
    private const string CONFIRMATION = 'REPLACE';

    public function __construct(
        private EntityManagerInterface $em,
        private BackupInspector $inspector,
        private BackupFitCheck $fitCheck,
        private AccountReset $accountReset,
        private RestoreLoader $loader,
    ) {
    }

    public function restore(User $user, string $gzipBytes, ?string $confirmation): RestoreResult
    {
        if (self::CONFIRMATION !== $confirmation) {
            throw new ValidationException(['confirm' => ['Type REPLACE to confirm the restore.']]);
        }

        $inventory = $this->inspector->inspect($gzipBytes);
        $this->fitCheck->assertFits($inventory, $user);
        $userId = (int) $user->getId();
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
        return $this->em->find(User::class, $userId)
            ?? throw new \LogicException('The account disappeared during its own restore.');
    }
}
