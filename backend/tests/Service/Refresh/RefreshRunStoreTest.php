<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunProgress;
use App\Service\Refresh\RefreshRunStore;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class RefreshRunStoreTest extends TestCase
{
    private const int BUDGET = 25;

    private RefreshRunStore $store;

    protected function setUp(): void
    {
        $this->store = new RefreshRunStore(new ArrayAdapter());
    }

    public function testAnUnknownRunOpensAtZero(): void
    {
        $progress = $this->store->open(RefreshRequest::forUser(1, self::BUDGET));

        self::assertSame(0, $progress->done);
        self::assertSame(0, $progress->total);
    }

    public function testASavedRunIsHandedBackToTheNextSlice(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $this->store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        $resumed = $this->store->open($request);

        self::assertSame(20, $resumed->done);
        self::assertSame(200, $resumed->total);
    }

    /** One user's sweep must never be handed to another's. */
    public function testRunsAreKeptApartByUser(): void
    {
        $this->store->save(
            RefreshRequest::forUser(1, self::BUDGET),
            RefreshRunProgress::start()->advancedBy(20, 180),
        );

        self::assertSame(0, $this->store->open(RefreshRequest::forUser(2, self::BUDGET))->done);
    }

    /**
     * Refreshing one feed while a whole sweep is in flight is a different run with
     * a different denominator; sharing a key would make each corrupt the other.
     */
    public function testRunsAreKeptApartByScope(): void
    {
        $userId = 1;
        $this->store->save(
            RefreshRequest::forUser($userId, self::BUDGET),
            RefreshRunProgress::start()->advancedBy(20, 180),
        );

        self::assertSame(0, $this->store->open(RefreshRequest::forUserFeed($userId, 7, self::BUDGET))->done);
        self::assertSame(0, $this->store->open(RefreshRequest::forUserTag($userId, 7, self::BUDGET))->done);
    }

    /** A feed scope and a tag scope with the same id are still two runs. */
    public function testAFeedScopeAndATagScopeWithTheSameIdDoNotCollide(): void
    {
        $userId = 1;
        $this->store->save(
            RefreshRequest::forUserFeed($userId, 7, self::BUDGET),
            RefreshRunProgress::start()->advancedBy(1, 0),
        );

        self::assertSame(0, $this->store->open(RefreshRequest::forUserTag($userId, 7, self::BUDGET))->done);
    }

    public function testAForgottenRunStartsOverNextTime(): void
    {
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $this->store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        $this->store->forget($request);

        self::assertSame(0, $this->store->open($request)->done);
    }

    /**
     * A cache file is not a contract. A truncated or stale-shaped entry must open
     * a fresh run rather than reach into an array that is not there.
     */
    public function testAMalformedEntryOpensAFreshRun(): void
    {
        $cache = new ArrayAdapter();
        $store = new RefreshRunStore($cache);
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        foreach (array_keys($cache->getValues()) as $key) {
            $item = $cache->getItem($key);
            $item->set('not an array');
            $cache->save($item);
        }

        self::assertSame(0, $store->open($request)->done);
    }

    /**
     * The CLI and maintenance sweeps build requests with no user and call
     * RefreshRunner directly. Reaching this store without a user is a wiring
     * mistake, and a silent shared key would pool every user's run into one.
     */
    public function testARequestWithNoUserIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->store->open(RefreshRequest::allDue(self::BUDGET));
    }

    /**
     * A string entry degrades to null on `['done']` and so never reaches the
     * `is_array` guard — only the `is_int` ones. An object throws instead, which
     * is what makes this the case that guard exists for.
     */
    public function testAnEntryOfTheWrongTypeEntirelyOpensAFreshRun(): void
    {
        $cache = new ArrayAdapter();
        $store = new RefreshRunStore($cache);
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        foreach (array_keys($cache->getValues()) as $key) {
            $item = $cache->getItem($key);
            $item->set(new \stdClass());
            $cache->save($item);
        }

        self::assertSame(0, $store->open($request)->done);
    }

    /**
     * `is_array` passing is not enough on its own: each field is checked
     * independently, so a shape with exactly one bad field must still open fresh
     * rather than hand a non-int through to `RefreshRunProgress::resumed()`.
     */
    public function testAnEntryWithOnlyDoneOfTheWrongTypeOpensAFreshRun(): void
    {
        $cache = new ArrayAdapter();
        $store = new RefreshRunStore($cache);
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        foreach (array_keys($cache->getValues()) as $key) {
            $item = $cache->getItem($key);
            $item->set(['done' => 'twenty', 'total' => 200]);
            $cache->save($item);
        }

        self::assertSame(0, $store->open($request)->done);
    }

    public function testAnEntryWithOnlyTotalOfTheWrongTypeOpensAFreshRun(): void
    {
        $cache = new ArrayAdapter();
        $store = new RefreshRunStore($cache);
        $request = RefreshRequest::forUser(1, self::BUDGET);
        $store->save($request, RefreshRunProgress::start()->advancedBy(20, 180));

        foreach (array_keys($cache->getValues()) as $key) {
            $item = $cache->getItem($key);
            $item->set(['done' => 20, 'total' => 'two-hundred']);
            $cache->save($item);
        }

        self::assertSame(0, $store->open($request)->done);
    }

    public function testSavingSetsATtlSoAnAbandonedRunEventuallyExpires(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('set')->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(600);

        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);

        $store = new RefreshRunStore($cache);
        $store->save(RefreshRequest::forUser(1, self::BUDGET), RefreshRunProgress::start());
    }

    /**
     * `keyFor()`/`scopeOf()` are private, so the key is observed the way the real
     * cache backend would see it: by asking the pool for its stored keys. Each
     * scope must produce its own distinct string, not merely A distinct string.
     */
    public function testTheCacheKeyEncodesTheUserAndTheRequestsScope(): void
    {
        self::assertSame(['refresh_run_3.all'], $this->rawKeyFor(RefreshRequest::forUser(3, self::BUDGET)));
        self::assertSame(
            ['refresh_run_3.feed-7'],
            $this->rawKeyFor(RefreshRequest::forUserFeed(3, 7, self::BUDGET)),
        );
        self::assertSame(
            ['refresh_run_3.tag-9'],
            $this->rawKeyFor(RefreshRequest::forUserTag(3, 9, self::BUDGET)),
        );
    }

    /** @return list<string> */
    private function rawKeyFor(RefreshRequest $request): array
    {
        $cache = new ArrayAdapter();
        (new RefreshRunStore($cache))->save($request, RefreshRunProgress::start());

        return array_keys($cache->getValues());
    }
}
