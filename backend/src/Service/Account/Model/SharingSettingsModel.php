<?php

declare(strict_types=1);

namespace App\Service\Account\Model;

final readonly class SharingSettingsModel
{
    /** @param list<string> $hiddenTargets */
    public function __construct(
        public bool $enabled,
        public array $hiddenTargets,
    ) {
    }
}
