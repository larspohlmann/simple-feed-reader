<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Entity\User;
use App\Enum\ScrapeFallback;

/**
 * The only place an account's preference becomes a discovery mode. Keeping the
 * mapping here is what lets FeedDiscovery stay free of any User dependency.
 */
final readonly class ScrapeFallbackPolicy
{
    public function forUser(User $user): ScrapeFallback
    {
        return $user->getPreferences()->isScrapeFallbackEnabled()
            ? ScrapeFallback::Enabled
            : ScrapeFallback::Disabled;
    }
}
