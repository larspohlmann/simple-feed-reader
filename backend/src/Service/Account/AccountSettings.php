<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Repository\ReadingSettingsRepository;
use App\Repository\SharingSettingsRepository;
use App\Service\Account\Model\AccountSettingsModel;

final readonly class AccountSettings
{
    public function __construct(
        private ReadingSettingsRepository $readingSettings,
        private SharingSettingsRepository $sharingSettings,
    ) {
    }

    public function of(User $user): AccountSettingsModel
    {
        return new AccountSettingsModel(
            $this->readingSettings->forUser($user),
            $this->sharingSettings->forUser($user),
        );
    }
}
