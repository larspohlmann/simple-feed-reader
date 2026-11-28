<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\User;
use App\Repository\YourStreamSettingsRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Curation\YourStream\Model\YourStreamSettingsStateModel;

final readonly class YourStreamSettingsStateReader
{
    public function __construct(
        private YourStreamSettingsRepository $streamSettings,
        private YourStreamWordRepository $words,
    ) {
    }

    public function readState(User $user): YourStreamSettingsStateModel
    {
        $userId = $user->requireId();

        return new YourStreamSettingsStateModel(
            $this->streamSettings->findTuningForUser($userId),
            $this->words->findForUser($userId),
        );
    }
}
