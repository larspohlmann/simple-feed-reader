<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/** The admin-set per-account overrides; null means no trial running and no cap override. */
final readonly class AdminUserLimits
{
    public function __construct(
        public ?string $trialEndsAt,
        public ?int $maxSubscriptions,
    ) {
    }
}
