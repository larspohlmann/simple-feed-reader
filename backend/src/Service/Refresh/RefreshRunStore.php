<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Remembers one run's progress between two of its slices.
 *
 * A cache pool, not a table: the record is two integers for a run that lasts minutes
 * and is worthless once it ends, so an entity, a migration, and an abandoned-run
 * sweeper would all be paid for nothing. TTL is the reaper — if an entry evaporates,
 * the next slice re-derives a denominator and the bar jumps once.
 *
 * The scope is part of the key on purpose: refreshing one feed during a whole sweep is
 * a different run with a different denominator, and a shared key would corrupt both.
 */
final readonly class RefreshRunStore
{
    /**
     * Comfortably longer than a run: a slice is budgeted at 25 s and a large sweep is
     * a handful of them. Short enough that an abandoned run — a closed tab, a phone
     * that slept — is gone long before the user comes back and starts a new one.
     */
    private const int LIFETIME_SECONDS = 600;
    private const string KEY_PREFIX = 'refresh_run_';

    public function __construct(private CacheItemPoolInterface $refreshRunCache)
    {
    }

    /** @throws InvalidArgumentException */
    public function open(RefreshRequest $request): RefreshRunProgress
    {
        $item = $this->refreshRunCache->getItem($this->keyFor($request));
        $stored = $item->isHit() ? $item->get() : null;

        // A cache file is not a contract: it survives deploys that change this
        // shape, and it can be truncated. An unreadable entry is a new run, not a
        // crash.
        if (!\is_array($stored) || !\is_int($stored['done'] ?? null) || !\is_int($stored['total'] ?? null)) {
            return RefreshRunProgress::start();
        }

        return RefreshRunProgress::resumed($stored['done'], $stored['total']);
    }

    /** @throws InvalidArgumentException */
    public function save(RefreshRequest $request, RefreshRunProgress $progress): void
    {
        $item = $this->refreshRunCache->getItem($this->keyFor($request));
        $item->set($progress->toArray());
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->refreshRunCache->save($item);
    }

    /** @throws InvalidArgumentException */
    public function forget(RefreshRequest $request): void
    {
        $this->refreshRunCache->deleteItem($this->keyFor($request));
    }

    private function keyFor(RefreshRequest $request): string
    {
        if (null === $request->userId) {
            throw new \LogicException(
                'A tracked refresh run needs a user. The CLI and maintenance sweeps call RefreshRunner directly.',
            );
        }

        return self::KEY_PREFIX . $request->userId . '.' . $this->scopeOf($request);
    }

    private function scopeOf(RefreshRequest $request): string
    {
        if (null !== $request->feedId) {
            return 'feed-' . $request->feedId;
        }

        if (null !== $request->tagId) {
            return 'tag-' . $request->tagId;
        }

        return 'all';
    }
}
