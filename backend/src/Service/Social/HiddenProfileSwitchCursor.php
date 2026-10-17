<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\RefusedFeedPositionModel;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the next hidden-profile switch resumes, so a full pass over the refused feeds spreads across runs, and how
 * soon the next run may read at all.
 */
final readonly class HiddenProfileSwitchCursor
{
    private const string POSITION = 'hidden_profile_switch_cursor';
    private const string NOT_BEFORE = 'hidden_profile_switch_not_before';

    public function __construct(
        #[Autowire(service: 'social.profile_visibility.cache')]
        private CacheItemPoolInterface $cache,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return RefusedFeedPositionModel|null null to start from the first refused subscription */
    public function position(): ?RefusedFeedPositionModel
    {
        $position = $this->cache->getItem(self::POSITION)->get();

        return $position instanceof RefusedFeedPositionModel ? $position : null;
    }

    /** @param RefusedFeedPositionModel|null $position null to start the next run from the first refused subscription */
    public function store(?RefusedFeedPositionModel $position): void
    {
        if (null !== $position) {
            $this->cache->save($this->cache->getItem(self::POSITION)->set($position));

            return;
        }
        $this->cache->deleteItem(self::POSITION);
    }

    /** @param string $delay a relative date modifier, such as '+1 hour' */
    public function pauseFor(string $delay): void
    {
        $this->cache->save($this->cache->getItem(self::NOT_BEFORE)->set($this->clock->now()->modify($delay)));
    }

    public function isPaused(): bool
    {
        $notBefore = $this->cache->getItem(self::NOT_BEFORE)->get();

        return $notBefore instanceof \DateTimeImmutable && $notBefore > $this->clock->now();
    }
}
