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
        $consumed = $this->store->consume($started->state);

        self::assertNotNull($consumed);
        self::assertSame('google', $consumed->provider);
        self::assertSame($started->nonce, $consumed->nonce);
        self::assertSame($started->codeVerifier, $consumed->codeVerifier);

        // Single use. A replayed callback — the browser's back button, or an
        // attacker resubmitting a captured redirect — must find nothing.
        self::assertNull($this->store->consume($started->state));
    }

    public function testAnUnknownStateIsRejected(): void
    {
        self::assertNull($this->store->consume('never-issued'));
    }

    public function testAnExpiredStateIsRejected(): void
    {
        $started = $this->store->start('google');
        $this->clock->modify('+11 minutes');

        self::assertNull($this->store->consume($started->state));
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
        self::assertNull($this->store->consume('some-other-state'));

        $this->clock->modify('+2 minutes');
        self::assertNull($this->store->consume($started->state));
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
