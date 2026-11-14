<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Discovery;

use App\Entity\User;
use App\Repository\ReadingSettingsRepository;
use App\Service\Sourcing\Discovery\Exception\ScrapingDisabledException;
use App\Service\Sourcing\Discovery\Model\ScrapeFallback;

/**
 * The only place an account's preference becomes a discovery mode. Keeping the
 * mapping here is what lets FeedDiscovery stay free of any User dependency.
 */
final readonly class ScrapeFallbackPolicy
{
    public function __construct(private ReadingSettingsRepository $readingSettings)
    {
    }

    public function forUser(User $user): ScrapeFallback
    {
        return $this->readingSettings->forUser($user)->isScrapeFallbackEnabled()
            ? ScrapeFallback::Enabled
            : ScrapeFallback::Disabled;
    }

    /**
     * Refuses a scraped subscribe or preview for an account with the preference off. Discovery never offers one to
     * such an account, so only a hand-made request gets here; SubscriptionService and FeedPreviewService share this.
     *
     * @throws ScrapingDisabledException
     */
    public function assertMayScrape(User $user): void
    {
        if (ScrapeFallback::Enabled !== $this->forUser($user)) {
            throw new ScrapingDisabledException();
        }
    }
}
