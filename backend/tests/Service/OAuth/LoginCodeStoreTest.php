<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\LoginCodeStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class LoginCodeStoreTest extends TestCase
{
    /**
     * Stands in for the flow cookie the callback authenticated. Shaped like the
     * real one — 64 hex characters — so the "not stored in the clear"
     * assertions below are searching for something of the right length.
     */
    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

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
        $code = $this->store->issue(42, self::TOKEN);

        self::assertSame(42, $this->store->consume($code, self::TOKEN));
        self::assertNull($this->store->consume($code, self::TOKEN));
    }

    public function testAnUnknownCodeReturnsNull(): void
    {
        self::assertNull($this->store->consume('not-a-code', self::TOKEN));
    }

    public function testACodeExpiresAfterThirtySeconds(): void
    {
        $code = $this->store->issue(42, self::TOKEN);
        $this->clock->modify('+31 seconds');

        self::assertNull($this->store->consume($code, self::TOKEN));
    }

    public function testACodeIsStillValidJustInsideTheWindow(): void
    {
        $code = $this->store->issue(42, self::TOKEN);
        $this->clock->modify('+29 seconds');

        self::assertSame(42, $this->store->consume($code, self::TOKEN));
    }

    /**
     * The deadline is anchored to issue time, not to the store's last activity.
     *
     * The clock is advanced in two steps with unrelated traffic in between, so
     * a store that reset the window whenever it was touched at all — rather
     * than only when the code itself was read — would keep this code alive past
     * T+30 and fail here.
     *
     * WHAT THIS DOES NOT PROVE, because the API cannot express it: that reading
     * THIS code does not extend it. `consume()` is the only read, and it
     * destroys what it reads, so there is no second look to take. That property
     * is structural instead of tested — `expires_at` is written once, in
     * `issue()`, and `expiresAfter()` is called nowhere else — and a rename of
     * this method to claim otherwise would be claiming an assertion that cannot
     * fail. The exposure being bounded is real either way: a store that
     * refreshed on read would let a code captured from a proxy log live
     * indefinitely so long as something kept touching it.
     */
    public function testTheWindowIsAnchoredToIssueTimeNotToTheLastStoreActivity(): void
    {
        $code = $this->store->issue(42, self::TOKEN);

        $this->clock->modify('+20 seconds');
        // A miss on an unrelated key: traffic through the store, touching
        // neither this entry nor its deadline.
        self::assertNull($this->store->consume('some-other-code', self::TOKEN));

        $this->clock->modify('+11 seconds');
        self::assertNull(
            $this->store->consume($code, self::TOKEN),
            'the code outlived T+30, so its deadline moved with the store rather than with its issue',
        );
    }

    public function testEachIssuedCodeIsDistinct(): void
    {
        self::assertNotSame($this->store->issue(1, self::TOKEN), $this->store->issue(1, self::TOKEN));
    }

    // -- The browser binding ----------------------------------------------

    /**
     * The code is not a bearer value, and this is the assertion that says so.
     *
     * Without it an attacker who completes a real sign-in in their own browser
     * can hand the resulting code to a victim inside its 30 seconds and have
     * the victim's SPA exchange it — signing the victim in AS THE ATTACKER. See
     * the class docblock; OAuthFlowTest drives the same attack over HTTP.
     */
    public function testACodeIsRefusedToADifferentBrowser(): void
    {
        $code = $this->store->issue(42, self::TOKEN);

        self::assertNull($this->store->consume($code, 'a-different-browsers-token'));
    }

    /**
     * The strip-rather-than-forge case. If no binding at all were lenient
     * anywhere, the check would be decorative: an attacker who can make a
     * request carry no cookie is strictly better off than one who must guess.
     */
    public function testACodeIsRefusedWhenNoBindingIsPresentedAtAll(): void
    {
        $code = $this->store->issue(42, self::TOKEN);

        self::assertNull($this->store->consume($code, null));
    }

    /**
     * An empty string is the shape a stripped cookie most plausibly arrives in
     * — `Cookie: __Host-oauth_flow=` is a string, not a null — and it must not
     * satisfy the comparison against any stored digest.
     */
    public function testAnEmptyBindingIsNotAWildcard(): void
    {
        $code = $this->store->issue(42, self::TOKEN);

        self::assertNull($this->store->consume($code, ''));

        $emptyBound = $this->store->issue(7, '');
        self::assertNull($this->store->consume($emptyBound, self::TOKEN));
    }

    /**
     * A wrong binding BURNS the code, so a mismatch cannot be retried with a
     * different guess against the same live code. consume() deletes before it
     * validates; this pins that ordering.
     */
    public function testAFailedBindingCheckStillDestroysTheCode(): void
    {
        $code = $this->store->issue(42, self::TOKEN);

        self::assertNull($this->store->consume($code, 'wrong'));
        self::assertNull($this->store->consume($code, self::TOKEN), 'the code survived a failed binding check');
    }

    /**
     * The code is a bearer credential for its 30 seconds — it trades directly
     * for a JWT — and the binding is one for as long as the flow lives. Neither
     * may appear as the cache key or inside the cached value.
     */
    public function testNeitherTheRawCodeNorTheRawBindingIsStored(): void
    {
        $code = $this->store->issue(42, self::TOKEN);

        $values = $this->cache->getValues();
        self::assertNotEmpty($values, 'nothing was cached, so the assertions below would be vacuous');

        foreach ($values as $key => $value) {
            self::assertStringNotContainsString($code, (string) $key);
            self::assertStringNotContainsString($code, serialize($value));
            self::assertStringNotContainsString(self::TOKEN, (string) $key);
            self::assertStringNotContainsString(self::TOKEN, serialize($value));
        }
    }
}
