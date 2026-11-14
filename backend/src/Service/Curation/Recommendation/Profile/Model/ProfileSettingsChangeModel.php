<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Profile\Model;

/** What the profile section asks to store; the connection is still an id the editor resolves for the account. */
final readonly class ProfileSettingsChangeModel
{
    public function __construct(
        public ?int $intervalHours,
        public ?int $connectionId,
        public int $keptCap,
        public int $viewedCap,
    ) {
    }
}
