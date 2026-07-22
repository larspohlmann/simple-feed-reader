<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * A throwaway EC P-256 keypair for the Apple client-secret tests, generated at
 * run time and never written to disk.
 *
 * ## Why this is not a fixture file
 *
 * It used to be `tests/Fixtures/oauth/apple-test-key.p8` — a real, if worthless,
 * EC private key committed to a public repository. Nothing was exposed by it:
 * it signs assertions for a Team ID and Key ID that do not exist, so the worst
 * an attacker can do with it is fail to authenticate to Apple.
 *
 * It was removed anyway, for two reasons that have nothing to do with that key:
 *
 * 1. **Secret scanners cannot tell.** GitHub push protection, trufflehog and
 *    every CI security step match on the PEM armour, not on whether the key is
 *    live. A committed `BEGIN PRIVATE KEY` is a permanent finding somebody has
 *    to trIage and then suppress — and a suppression is a thing that later
 *    hides a real one.
 * 2. **This is a showcase repository, so the pattern gets copied.** "The tests
 *    read the signing key from a file in the repo" is a fine shape right up
 *    until somebody follows it with a key that matters. Generating the key
 *    means there is no file to helpfully replace.
 *
 * ## Why generated rather than gitignored
 *
 * A gitignored `var/` file would also keep it out of the repo, but it adds a
 * path that must exist before the suite runs, a cleanup story, and a way for a
 * stale key to survive across runs. The keypair costs about a millisecond and
 * is memoised for the process, so generating it is cheaper than managing it.
 *
 * ## What this does not change
 *
 * Every test that used the fixture verified a signature it had just produced,
 * against the matching public half — none of them pinned key material or a
 * fixed signature. So the values being different on every run is invisible to
 * them, which is exactly why this swap was safe to make.
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
