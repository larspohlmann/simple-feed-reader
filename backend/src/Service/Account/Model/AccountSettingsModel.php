<?php

declare(strict_types=1);

namespace App\Service\Account\Model;

use App\Entity\ReadingSettings;

final readonly class AccountSettingsModel
{
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public ReadingSettings $reading,
    ) {
    }
}
