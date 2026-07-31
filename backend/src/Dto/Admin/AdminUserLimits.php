<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/**
 * The admin's per-account overrides: a trial expiry and a subscription cap,
 * both admin-set and both nullable — no trial running, no cap override in
 * force. Kept as its own section of the detail payload, distinct from
 * {@see AdminUserAccount}, so adding an override never grows that DTO's
 * constructor.
 */
final readonly class AdminUserLimits
{
    public function __construct(
        public ?string $trialEndsAt,
        public ?int $maxSubscriptions,
    ) {
    }
}
