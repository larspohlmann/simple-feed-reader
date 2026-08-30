<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;

/**
 * Remembers one run's progress between two of its slices.
 *
 * A cache pool, not a table. The record is two integers describing a run that lasts a
 * couple of minutes and is worthless the moment it ends: an entity, a migration, a CI
 * migration leg and a sweeper for abandoned runs would all be paid for nothing. The
 * TTL is the reaper. If an entry evaporates — a cleared cache, a moved deploy
 * directory — the next slice re-derives a denominator from itself and the bar jumps
 * once, which is the whole cost of losing it.
 *
 * The scope is part of the key on purpose. Refreshing one feed while a whole sweep is
 * in flight is a different run with a different denominator, and a shared key would
 * make each corrupt the other.
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
