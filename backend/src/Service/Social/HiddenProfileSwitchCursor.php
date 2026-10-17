<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Model\RefusedFeedPositionModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Where the next hidden-profile switch resumes, so a full pass over the refused feeds spreads across runs. */
final readonly class HiddenProfileSwitchCursor
{
    private const string KEY = 'hidden_profile_switch_cursor';

    public function __construct(
        #[Autowire(service: 'social.profile_visibility.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /** @return RefusedFeedPositionModel|null null to start from the first refused subscription */
    public function position(): ?RefusedFeedPositionModel
    {
        $position = $this->cache->getItem(self::KEY)->get();

        return $position instanceof RefusedFeedPositionModel ? $position : null;
    }

    public function resumeAfter(RefusedFeedPositionModel $position): void
    {
        $this->cache->save($this->cache->getItem(self::KEY)->set($position));
    }

    public function restart(): void
    {
        $this->cache->deleteItem(self::KEY);
    }
}
