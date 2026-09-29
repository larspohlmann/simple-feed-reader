<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A throwaway EC P-256 keypair for the Apple client-secret tests, generated per process and never written to disk:
 * a committed PEM is a permanent secret-scanner finding, and a pattern others copy with a key that matters.
 */
final class AppleTestKey
{
    private static ?string $privateKey = null;
    private static ?string $publicKey = null;

    /** PKCS#8 PEM, the format Apple hands out and APPLE_OAUTH_PRIVATE_KEY takes. */
    public static function privateKey(): string
    {
        self::generate();
        \assert(null !== self::$privateKey);

        return self::$privateKey;
    }

    /** The matching public half, for tests that verify what the factory signed. */
    public static function publicKey(): string
    {
        self::generate();
        \assert(null !== self::$publicKey);

        return self::$publicKey;
    }

    /**
     * P-256 because that is the curve Apple's ES256 assertion requires, and the
     * one AppleClientSecretFactory's Sha256 signer expects. A key on any other
     * curve would fail at signing time rather than proving anything.
     */
    private static function generate(): void
    {
        if (null !== self::$privateKey) {
            return;
        }

        $key = openssl_pkey_new([
            'private_key_type' => \OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if (false === $key) {
            throw new \RuntimeException('could not generate an EC test key: ' . openssl_error_string());
        }

        $private = null;

        // Written by reference, so its type is not inferable from the call —
        // checked rather than asserted, because a failed export returns false
        // and leaves the variable untouched.
        if (!openssl_pkey_export($key, $private) || !\is_string($private)) {
            throw new \RuntimeException('could not export the generated EC test key');
        }

        $details = openssl_pkey_get_details($key);

        if (!\is_array($details) || !\is_string($details['key'] ?? null)) {
            throw new \RuntimeException('could not read the generated key back');
        }

        self::$privateKey = $private;
        self::$publicKey = $details['key'];
    }
}
