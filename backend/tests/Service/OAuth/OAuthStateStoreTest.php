<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\Exception\InvalidOAuthStateException;
use App\Service\OAuth\OAuthStateStore;
use App\Tests\Support\AssertsRefusal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class OAuthStateStoreTest extends TestCase
{
    use AssertsRefusal;

    private ArrayAdapter $cache;
    private MockClock $clock;
    private OAuthStateStore $store;

    /**
     * `storeSerialized: false`, so getValues() returns the payload as written, not a serialised blob that could hide
     * the plaintext state.
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

        self::assertSame('google', $consumed->provider);
        self::assertSame($started->nonce, $consumed->nonce);
        self::assertSame($started->codeVerifier, $consumed->codeVerifier);

        // Single use. A replayed callback — the browser's back button, or an
        // attacker resubmitting a captured redirect — must find nothing.
        $this->assertRefused(
            fn () => $this->store->consume($started->state, $started->browserToken),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    public function testAnUnknownStateIsRejected(): void
    {
        $this->assertRefused(
            fn () => $this->store->consume('never-issued', 'irrelevant'),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    public function testAnExpiredStateIsRejected(): void
    {
        $started = $this->store->start('google');
        $this->clock->modify('+11 minutes');

        $this->assertRefused(
            fn () => $this->store->consume($started->state, $started->browserToken),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    /** A genuine, unspent state redeemed without its browser token buys nothing; null is the no-cookie callback. */
    public function testAFlowCannotBeConsumedWithoutItsBrowserToken(): void
    {
        $started = $this->store->start('google');

        $this->assertRefused(
            fn () => $this->store->consume($started->state, null),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    public function testAFlowCannotBeConsumedWithTheWrongBrowserToken(): void
    {
        $started = $this->store->start('google');

        $this->assertRefused(
            fn () => $this->store->consume($started->state, str_repeat('a', 64)),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    /** Another live flow's browser token is not a skeleton key. */
    public function testOneFlowsBrowserTokenDoesNotRedeemAnother(): void
    {
        $firstFlow = $this->store->start('google');
        $secondFlow = $this->store->start('google');

        $this->assertRefused(
            fn () => $this->store->consume($firstFlow->state, $secondFlow->browserToken),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    /** A wrong token burns the state, so a genuine state cannot be retried against the binding indefinitely. */
    public function testAWrongBrowserTokenBurnsTheState(): void
    {
        $started = $this->store->start('google');

        $this->assertRefused(
            fn () => $this->store->consume($started->state, 'wrong'),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );

        // Even the right token cannot recover it now.
        $this->assertRefused(
            fn () => $this->store->consume($started->state, $started->browserToken),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    public function testEveryFlowGetsADistinctBrowserToken(): void
    {
        $firstFlow = $this->store->start('google');
        $secondFlow = $this->store->start('google');

        self::assertNotNull($firstFlow->browserToken);
        self::assertNotSame($firstFlow->browserToken, $secondFlow->browserToken);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $firstFlow->browserToken);
    }

    /** The binding is stored only as a digest, like the state: the pool is a directory of files. */
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
        $this->assertRefused(
            fn () => $this->store->consume('some-other-state', 'irrelevant'),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );

        $this->clock->modify('+2 minutes');
        $this->assertRefused(
            fn () => $this->store->consume($started->state, $started->browserToken),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }

    public function testTheCodeChallengeIsTheS256OfTheVerifier(): void
    {
        $started = $this->store->start('google');

        $expected = rtrim(strtr(base64_encode(hash('sha256', $started->codeVerifier, true)), '+/', '-_'), '=');
        self::assertSame($expected, $started->codeChallenge);
    }

    public function testEveryFlowGetsDistinctSecrets(): void
    {
        $firstFlow = $this->store->start('google');
        $secondFlow = $this->store->start('google');

        self::assertNotSame($firstFlow->state, $secondFlow->state);
        self::assertNotSame($firstFlow->nonce, $secondFlow->nonce);
        self::assertNotSame($firstFlow->codeVerifier, $secondFlow->codeVerifier);
    }

    /** The raw state appears neither as the key nor in the value: either would leave a usable state on disk. */
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

    /**
     * A stored entry that is not the shape start() writes — corrupted on disk,
     * or written by a future version this one cannot read — must refuse rather
     * than emit a TypeError from an undefined array key.
     */
    public function testACorruptStoredEntryIsRefused(): void
    {
        $started = $this->store->start('google');

        $key = array_key_first($this->cache->getValues());
        self::assertIsString($key);
        $item = $this->cache->getItem($key);
        $item->set(['provider' => 'google']);
        $this->cache->save($item);

        $this->assertRefused(
            fn () => $this->store->consume($started->state, $started->browserToken),
            InvalidOAuthStateException::class,
            'The state was redeemed.',
        );
    }
}
