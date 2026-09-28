<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;

final readonly class RestoreDestination
{
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public User $user,
        public RestoreFeedTargets $feeds,
    ) {
    }
}
