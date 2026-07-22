<?php

declare(strict_types=1);

namespace App\Tests\Dto\OAuth;

use App\Dto\OAuth\OAuthIdentity;
use PHPUnit\Framework\TestCase;

final class OAuthIdentityTest extends TestCase
{
    public function testALinkableAddressIsVerifiedAndNotPrivateRelay(): void
    {
        $identity = new OAuthIdentity('google', 'sub-1', 'Bob@Example.com', true);

        self::assertSame('bob@example.com', $identity->email);
        self::assertTrue($identity->isLinkableByEmail());
    }

    public function testAnUnverifiedAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentity('google', 'sub-1', 'bob@example.com', false);

        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testAMissingAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentity('apple', 'sub-1', null, false);

        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testAnApplePrivateRelayAddressIsNotLinkable(): void
    {
        // Verified by Apple, and still not linkable: the address is minted per
        // app-and-user, so it can never be the address someone signed up with.
        $identity = new OAuthIdentity('apple', 'sub-1', 'abc123@privaterelay.appleid.com', true);

        self::assertTrue($identity->isPrivateRelay());
        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testPrivateRelayDetectionIsCaseInsensitiveAndAnchored(): void
    {
        self::assertTrue(
            (new OAuthIdentity('apple', 's', 'X@PrivateRelay.AppleID.com', true))->isPrivateRelay(),
        );

        // A lookalike domain an attacker can actually register must NOT be
        // treated as a relay address — that would be harmless here, but the
        // same predicate must never mistake it the other way round either.
        self::assertFalse(
            (new OAuthIdentity('apple', 's', 'x@privaterelay.appleid.com.evil.test', true))->isPrivateRelay(),
        );
    }

    /**
     * Pins the left-hand anchor, which the suffix test above cannot: without
     * the '@' in the comparison, every one of these would be read as a relay
     * address. Apple mints relay addresses on the bare domain only — there is
     * no `sub.privaterelay.appleid.com` — so an exact domain match is both
     * correct and the narrower, safer reading.
     */
    public function testOnlyTheExactRelayDomainCounts(): void
    {
        $lookalikes = [
            'x@sub.privaterelay.appleid.com',
            'x@notprivaterelay.appleid.com',
            'x@evil.test?privaterelay.appleid.com',
            'privaterelay.appleid.com@example.com',
        ];

        foreach ($lookalikes as $email) {
            self::assertFalse(
                (new OAuthIdentity('apple', 's', $email, true))->isPrivateRelay(),
                $email . ' must not be read as a private relay address',
            );
        }
    }

    /**
     * A provider that sends `"email": ""` — or a string of spaces — is saying
     * it has no address for this user, not that the user's address is the
     * empty string. Left as-is, an empty string is non-null and would sail
     * through isLinkableByEmail()'s null check, so it is collapsed to null at
     * construction: exactly one representation of "no address" reaches the
     * linking rules.
     */
    public function testABlankAddressIsTreatedAsAbsentAndIsNotLinkable(): void
    {
        foreach (['', '   ', "\t\n"] as $blank) {
            $identity = new OAuthIdentity('google', 'sub-1', $blank, true);

            self::assertNull($identity->email);
            self::assertFalse($identity->isLinkableByEmail());
            self::assertFalse($identity->isPrivateRelay());
        }
    }
}
