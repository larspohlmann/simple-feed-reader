<?php

declare(strict_types=1);

namespace App\Service\Identity\Account\Model;

final readonly class SharingSettingsModel
{
    /** @param list<string> $hiddenTargets */
    public function __construct(
        public bool $enabled,
        public array $hiddenTargets,
        public bool $rememberShares,
    ) {
    }
}
