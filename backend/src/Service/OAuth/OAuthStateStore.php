<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Service\OAuth\Exception\InvalidOAuthStateException;
use App\Service\OAuth\Model\OAuthStartStateModel;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Random\RandomException;

/**
 * Holds a flow's secrets between the redirect and the callback, server-side because the API has no session. A digest
 * of the flow cookie binds the flow to its browser; a missing or wrong cookie must fail like an unknown state.
 * Storage and the accepted race: docs/oauth-sign-in.md#storage-and-the-accepted-race
 */
final readonly class OAuthStateStore
{
    public const int LIFETIME_SECONDS = 600;
    private const string KEY_PREFIX = 'oauth_state_';

    public function __construct(
        private CacheItemPoolInterface $oauthStateCache,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    public function start(string $provider): OAuthStartStateModel
    {
        $state = self::randomToken();
        $nonce = self::randomToken();

        // PKCE verifier: 43-128 unreserved characters per RFC 7636 4.1.
        // 32 random bytes hex-encoded is 64, comfortably inside that range and
        // free of any character needing escaping in a form-encoded body.
        $codeVerifier = self::randomToken();

        // Goes to the browser in a cookie, never to the provider. See the class
        // docblock: this is what makes `state` mean "this browser".
        $browserToken = self::randomToken();

        $started = new OAuthStartStateModel(
            $provider,
            $state,
            $nonce,
            $codeVerifier,
            self::challengeFor($codeVerifier),
            $browserToken,
        );

        $item = $this->oauthStateCache->getItem(self::keyFor($state));
        // Neither the state nor the browser token is stored in readable form: the
        // state is the hashed key and the token only a digest, so a readable cache
        // file yields neither a usable state nor a usable binding.
        $item->set([
            'provider' => $provider,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'browser_digest' => self::digest($browserToken),
            'expires_at' => $this->clock->now()->getTimestamp() + self::LIFETIME_SECONDS,
        ]);
        $item->expiresAfter(self::LIFETIME_SECONDS);
        $this->oauthStateCache->save($item);

        return $started;
    }

    /**
     * @throws InvalidOAuthStateException when the state is unknown, spent, expired, or presented by another browser
     * @throws InvalidArgumentException
     */
    public function consume(string $state, ?string $browserToken): OAuthStartStateModel
    {
        $key = self::keyFor($state);
        $item = $this->oauthStateCache->getItem($key);

        if (!$item->isHit()) {
            throw new InvalidOAuthStateException();
        }

        // Deleted before validation, so a failed check burns the state instead of leaving it to retry.
        $this->oauthStateCache->deleteItem($key);

        $stored = self::decodeStored($item->get());

        // hash_equals: the stored digest is secret-derived, and a byte-wise compare leaks its prefix.
        if (null === $browserToken || !hash_equals($stored['browser_digest'], self::digest($browserToken))) {
            throw new InvalidOAuthStateException();
        }

        // The pool's TTL runs on the cache backend's clock; this runs on the injected one.
        if ($stored['expires_at'] < $this->clock->now()->getTimestamp()) {
            throw new InvalidOAuthStateException();
        }

        $codeVerifier = $stored['code_verifier'];

        return new OAuthStartStateModel(
            $stored['provider'],
            $state,
            $stored['nonce'],
            $codeVerifier,
            self::challengeFor($codeVerifier),
        );
    }

    /**
     * @return array{provider: string, nonce: string, code_verifier: string, browser_digest: string, expires_at: int}
     *
     * @throws InvalidOAuthStateException when the entry is corrupt or tampered with
     */
    private static function decodeStored(mixed $stored): array
    {
        if (
            !\is_array($stored)
            || !\is_string($stored['provider'] ?? null)
            || !\is_string($stored['nonce'] ?? null)
            || !\is_string($stored['code_verifier'] ?? null)
            || !\is_string($stored['browser_digest'] ?? null)
            || !\is_int($stored['expires_at'] ?? null)
        ) {
            throw new InvalidOAuthStateException();
        }

        return [
            'provider' => $stored['provider'],
            'nonce' => $stored['nonce'],
            'code_verifier' => $stored['code_verifier'],
            'browser_digest' => $stored['browser_digest'],
            'expires_at' => $stored['expires_at'],
        ];
    }

    /**
     * base64url(sha256(verifier)), the `S256` method of RFC 7636 4.2. The
     * plain method is not offered: it would put the verifier in the redirect
     * URL, which is the exact exposure PKCE exists to remove.
     */
    private static function challengeFor(string $codeVerifier): string
    {
        return Base64UrlSafe::encodeUnpadded(hash('sha256', $codeVerifier, true));
    }

    private static function keyFor(string $state): string
    {
        return self::KEY_PREFIX . self::digest($state);
    }

    /**
     * Unsalted SHA-256 is enough: every input is 32 random bytes. docs/oauth-sign-in.md#storage-and-the-accepted-race
     */
    private static function digest(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @throws RandomException
     */
    private static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
