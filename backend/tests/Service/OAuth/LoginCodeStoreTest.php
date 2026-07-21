<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\LoginCodeStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LoginCodeStoreTest extends TestCase
{
    private ArrayAdapter $cache;
    private MockClock $clock;
    private LoginCodeStore $store;

    /**
     * `storeSerialized: false` so getValues() returns the payload as the store
     * wrote it; otherwise the "code is absent from the value" assertion would
     * be searching a serialised blob and could pass for the wrong reason.
     */
    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(storeSerialized: false);
        $this->clock = new MockClock('2026-07-21 12:00:00');
        $this->store = new LoginCodeStore($this->cache, $this->clock);
    }

    public function testACodeResolvesToItsUserOnce(): void
    {
        $code = $this->store->issue(42);

        self::assertSame(42, $this->store->consume($code));
        self::assertNull($this->store->consume($code));
    }

    public function testAnUnknownCodeReturnsNull(): void
    {
        self::assertNull($this->store->consume('not-a-code'));
    }

    public function testACodeExpiresAfterThirtySeconds(): void
    {
        $code = $this->store->issue(42);
        $this->clock->modify('+31 seconds');

        self::assertNull($this->store->consume($code));
    }

    public function testACodeIsStillValidJustInsideTheWindow(): void
    {
        $code = $this->store->issue(42);
        $this->clock->modify('+29 seconds');

        self::assertSame(42, $this->store->consume($code));
    }

    /**
     * The 30 seconds run from issue, and nothing restarts them.
     *
     * Worth pinning rather than assuming: a store that refreshed the TTL on
     * read would let a code captured from a proxy log stay alive indefinitely
     * as long as something kept touching it, which is precisely the exposure
     * the short window exists to bound.
     */
    public function testTheWindowRunsFromIssueAndIsNotExtendedByIntermediateReads(): void
    {
        $code = $this->store->issue(42);

        $this->clock->modify('+20 seconds');
        // Misses on other codes, and a miss on this one later, must not act as
        // keep-alives.
        self::assertNull($this->store->consume('some-other-code'));

        $this->clock->modify('+11 seconds');
        self::assertNull($this->store->consume($code), 'the code outlived T+30 despite an intervening read');
    }

    public function testEachIssuedCodeIsDistinct(): void
    {
        self::assertNotSame($this->store->issue(1), $this->store->issue(1));
    }

    /**
     * The code is a bearer credential for its 30 seconds — it trades directly
     * for a JWT. It may therefore appear neither as the cache key nor inside
     * the cached value.
     */
    public function testTheRawCodeIsStoredNeitherAsKeyNorInTheValue(): void
    {
        $code = $this->store->issue(42);

        $values = $this->cache->getValues();
        self::assertNotEmpty($values, 'nothing was cached, so the assertions below would be vacuous');

        foreach ($values as $key => $value) {
            self::assertStringNotContainsString($code, (string) $key);
            self::assertStringNotContainsString($code, serialize($value));
        }
    }
}
