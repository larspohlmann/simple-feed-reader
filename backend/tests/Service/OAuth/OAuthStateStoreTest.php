<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\OAuthStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class OAuthStateStoreTest extends TestCase
{
    private ArrayAdapter $cache;
    private MockClock $clock;
    private OAuthStateStore $store;

    /**
     * `storeSerialized: false` so getValues() below returns the payload as the
     * store wrote it. With serialisation on, the assertion that the plaintext
     * state is absent from stored values would be searching a serialised blob
     * and could pass for the wrong reason.
     */
    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter(storeSerialized: false);
        $this->clock = new MockClock('2026-07-21 12:00:00');
        $this->store = new OAuthStateStore($this->cache, $this->clock);
    }

    public function testAStartedFlowCanBeConsumedOnce(): void
    {
        $started = $this->store->start('google');
        $consumed = $this->store->consume($started->state, $started->browserToken);

        self::assertNotNull($consumed);
        self::assertSame('google', $consumed->provider);
        self::assertSame($started->nonce, $consumed->nonce);
        self::assertSame($started->codeVerifier, $consumed->codeVerifier);

        // Single use. A replayed callback — the browser's back button, or an
        // attacker resubmitting a captured redirect — must find nothing.
        self::assertNull($this->store->consume($started->state, $started->browserToken));
    }

    public function testAnUnknownStateIsRejected(): void
    {
        self::assertNull($this->store->consume('never-issued', 'irrelevant'));
    }

    public function testAnExpiredStateIsRejected(): void
    {
        $started = $this->store->start('google');
        $this->clock->modify('+11 minutes');

        self::assertNull($this->store->consume($started->state, $started->browserToken));
    }

    // -- The browser binding ----------------------------------------------

    /**
     * The property this store gained when login CSRF was found: a genuine,
     * unspent state redeemed by a browser that did not start the flow buys
     * nothing.
     *
     * `null` is the case that matters most — it is what the controller passes
     * when the callback arrived with no cookie at all, which is exactly what an
     * attacker replaying a captured state from a victim's browser produces. A
     * missing binding must be a failure, never a reason to skip the check.
     */
    public function testAFlowCannotBeConsumedWithoutItsBrowserToken(): void
    {
        $started = $this->store->start('google');

        self::assertNull($this->store->consume($started->state, null));
    }

    public function testAFlowCannotBeConsumedWithTheWrongBrowserToken(): void
    {
        $started = $this->store->start('google');

        self::assertNull($this->store->consume($started->state, str_repeat('a', 64)));
    }

    /**
     * A binding from a DIFFERENT live flow is not a skeleton key. Two flows
     * started in the same process must not be interchangeable, which is what a
     * single shared secret — or a digest computed over something constant —
     * would quietly produce.
     */
    public function testOneFlowsBrowserTokenDoesNotRedeemAnother(): void
    {
        $a = $this->store->start('google');
        $b = $this->store->start('google');

        self::assertNull($this->store->consume($a->state, $b->browserToken));
    }

    /**
     * A wrong token BURNS the state rather than leaving it live.
     *
     * Without this the binding would rest on the 64-hex search space alone: an
     * attacker holding a genuine state could retry it against the same flow
     * indefinitely. consume() deletes before it validates, and this is what
     * pins that ordering.
     */
    public function testAWrongBrowserTokenBurnsTheState(): void
    {
        $started = $this->store->start('google');

        self::assertNull($this->store->consume($started->state, 'wrong'));

        // Even the right token cannot recover it now.
        self::assertNull($this->store->consume($started->state, $started->browserToken));
    }

    public function testEveryFlowGetsADistinctBrowserToken(): void
    {
        $a = $this->store->start('google');
        $b = $this->store->start('google');

        self::assertNotNull($a->browserToken);
        self::assertNotSame($a->browserToken, $b->browserToken);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->browserToken);
    }

    /**
     * The binding is stored as a digest, never in the clear — the same rule the
     * state itself follows, and for the same reason: for the ten minutes a flow
     * is live the token is a bearer credential, and the pool is a directory of
     * files on hosting we do not own exclusively.
     */
    public function testTheBrowserTokenIsStoredOnlyAsADigest(): void
    {
        $started = $this->store->start('google');
        self::assertNotNull($started->browserToken);

        $values = $this->cache->getValues();
        self::assertNotEmpty($values, 'nothing was cached, so the assertion below would be vacuous');

        foreach ($values as $value) {
            self::assertStringNotContainsString($started->browserToken, serialize($value));
            self::assertStringContainsString(hash('sha256', $started->browserToken), serialize($value));
        }
    }

    /**
     * Expiry is measured from issue and is not refreshed by anything. There is
     * no read path that could extend it today, but asserting it pins the
     * property rather than leaving it as an accident of the current shape.
     */
    public function testExpiryRunsFromIssueNotFromLastTouch(): void
    {
        $started = $this->store->start('google');

        $this->clock->modify('+9 minutes');
        // A miss on a different state must not act as a keep-alive for this one.
        self::assertNull($this->store->consume('some-other-state', 'irrelevant'));

        $this->clock->modify('+2 minutes');
        self::assertNull($this->store->consume($started->state, $started->browserToken));
    }

    public function testTheCodeChallengeIsTheS256OfTheVerifier(): void
    {
        $started = $this->store->start('google');

        $expected = rtrim(strtr(base64_encode(hash('sha256', $started->codeVerifier, true)), '+/', '-_'), '=');
        self::assertSame($expected, $started->codeChallenge);
    }

    public function testEveryFlowGetsDistinctSecrets(): void
    {
        $a = $this->store->start('google');
        $b = $this->store->start('google');

        self::assertNotSame($a->state, $b->state);
        self::assertNotSame($a->nonce, $b->nonce);
        self::assertNotSame($a->codeVerifier, $b->codeVerifier);
    }

    /**
     * The raw state is a bearer value for the ten minutes a flow is live.
     * Anyone who can read the cache directory should not be handed a working
     * one — which means it may appear neither in the key nor in the payload.
     * Hashing the key would be pointless theatre if the plaintext sat in the
     * value beside it.
     */
    public function testTheRawStateIsStoredNeitherAsKeyNorInTheValue(): void
    {
        $started = $this->store->start('google');

        $values = $this->cache->getValues();
        self::assertNotEmpty($values, 'nothing was cached, so the assertions below would be vacuous');

        foreach ($values as $key => $value) {
            self::assertStringNotContainsString($started->state, (string) $key);
            self::assertStringNotContainsString($started->state, serialize($value));
        }
    }
}
