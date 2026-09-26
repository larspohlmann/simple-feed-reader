<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Dto\OAuth\OAuthCallbackAttempt;
use App\Dto\OAuth\OAuthIdentity;
use App\Dto\OAuth\OAuthStartState;
use App\Service\OAuth\Exception\OAuthCallbackRefusedException;
use App\Service\OAuth\OAuthCallback;
use App\Service\OAuth\OAuthCallbackFailure;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthSignIn;
use App\Service\OAuth\OAuthStateStore;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeOAuthProvider;
use App\Tests\Support\RecordingLogger;

final class OAuthCallbackTest extends DbTestCase
{
    public function testADeclinedConsentScreenIsRefusedAsAccessDeniedWithoutContactingTheProvider(): void
    {
        $provider = $this->provider();
        $attempt = new OAuthCallbackAttempt('google', true, null, null, null);

        self::assertSame(
            OAuthCallbackFailure::AccessDenied,
            $this->refusalOf($this->oauthCallback($provider), $attempt),
        );
        self::assertSame([], $provider->exchanges);
    }

    public function testAMissingCodeIsRefusedAsAnInvalidRequest(): void
    {
        $started = $this->stateStore()->start('google');
        $attempt = new OAuthCallbackAttempt('google', false, $started->state, null, $started->browserToken);
        $callback = $this->oauthCallback($this->provider());

        self::assertSame(OAuthCallbackFailure::InvalidRequest, $this->refusalOf($callback, $attempt));
        self::assertNotSame('', $callback->complete($this->attemptFor($started)));
    }

    public function testAMissingStateIsRefusedAsAnInvalidRequest(): void
    {
        $attempt = new OAuthCallbackAttempt('google', false, null, 'the-code', 'the-browser');

        self::assertSame(
            OAuthCallbackFailure::InvalidRequest,
            $this->refusalOf($this->oauthCallback($this->provider()), $attempt),
        );
    }

    public function testAnUnissuedStateIsRefusedAsInvalidState(): void
    {
        $provider = $this->provider();
        $attempt = new OAuthCallbackAttempt('google', false, 'never-issued', 'the-code', 'the-browser');

        self::assertSame(
            OAuthCallbackFailure::InvalidState,
            $this->refusalOf($this->oauthCallback($provider), $attempt),
        );
        self::assertSame([], $provider->exchanges);
    }

    public function testAStateReplayedAtAnotherProvidersCallbackIsRefusedAndBurned(): void
    {
        $provider = $this->provider();
        $callback = $this->oauthCallback($provider);
        $started = $this->stateStore()->start('google');
        $atApple = new OAuthCallbackAttempt('apple', false, $started->state, 'the-code', $started->browserToken);

        self::assertSame(OAuthCallbackFailure::InvalidState, $this->refusalOf($callback, $atApple));
        self::assertSame(OAuthCallbackFailure::InvalidState, $this->refusalOf($callback, $this->attemptFor($started)));
        self::assertSame([], $provider->exchanges);
    }

    public function testAFailedExchangeIsRefusedAndLoggedWithItsDetail(): void
    {
        $logger = new RecordingLogger();
        $started = $this->stateStore()->start('google');

        $failure = $this->refusalOf(
            $this->oauthCallback($this->provider(failExchange: true), $logger),
            $this->attemptFor($started),
        );

        self::assertSame(OAuthCallbackFailure::ExchangeFailed, $failure);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('OAuth exchange failed', $logger->records[0]['message']);
        self::assertSame('google', $logger->records[0]['context']['provider']);
        self::assertSame('fake provider was told to fail', $logger->records[0]['context']['detail']);
        self::assertArrayHasKey('exception', $logger->records[0]['context']);
        self::assertNull($logger->records[0]['context']['exception']);
    }

    public function testACompletedCallbackExchangesThisFlowsSecretsAndIssuesALoginCode(): void
    {
        $provider = $this->provider();
        $started = $this->stateStore()->start('google');

        $loginCode = $this->oauthCallback($provider)->complete($this->attemptFor($started));

        self::assertNotSame('', $loginCode);
        self::assertSame(
            [['code' => 'the-code', 'codeVerifier' => $started->codeVerifier, 'nonce' => $started->nonce]],
            $provider->exchanges,
        );
    }

    private function attemptFor(OAuthStartState $started): OAuthCallbackAttempt
    {
        return new OAuthCallbackAttempt('google', false, $started->state, 'the-code', $started->browserToken);
    }

    private function refusalOf(OAuthCallback $callback, OAuthCallbackAttempt $attempt): OAuthCallbackFailure
    {
        try {
            $callback->complete($attempt);
        } catch (OAuthCallbackRefusedException $refusal) {
            return $refusal->failure;
        }

        self::fail('The callback was not refused.');
    }

    private function oauthCallback(FakeOAuthProvider $provider, ?RecordingLogger $logger = null): OAuthCallback
    {
        $signIn = self::getContainer()->get(OAuthSignIn::class);
        self::assertInstanceOf(OAuthSignIn::class, $signIn);

        return new OAuthCallback(
            $this->stateStore(),
            new OAuthProviderRegistry([$provider]),
            $signIn,
            $logger ?? new RecordingLogger(),
        );
    }

    private function provider(bool $failExchange = false): FakeOAuthProvider
    {
        return new FakeOAuthProvider(
            new OAuthIdentity('google', 'sub-callback', 'callback@example.com', true),
            $failExchange,
        );
    }

    private function stateStore(): OAuthStateStore
    {
        $store = self::getContainer()->get(OAuthStateStore::class);
        self::assertInstanceOf(OAuthStateStore::class, $store);

        return $store;
    }
}
