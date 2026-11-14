<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth\Model;

use App\Service\OAuth\Model\OAuthIdentityModel;
use PHPUnit\Framework\TestCase;

final class OAuthIdentityModelTest extends TestCase
{
    public function testALinkableAddressIsVerifiedAndNotPrivateRelay(): void
    {
        $identity = new OAuthIdentityModel('google', 'sub-1', 'Bob@Example.com', true);

        self::assertSame('bob@example.com', $identity->email);
        self::assertTrue($identity->isLinkableByEmail());
    }

    public function testAnUnverifiedAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentityModel('google', 'sub-1', 'bob@example.com', false);

        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testAMissingAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentityModel('apple', 'sub-1', null, false);

        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testAnApplePrivateRelayAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentityModel('apple', 'sub-1', 'abc123@privaterelay.appleid.com', true);

        self::assertTrue($identity->isPrivateRelay());
        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testPrivateRelayDetectionIsCaseInsensitiveAndAnchored(): void
    {
        self::assertTrue(
            (new OAuthIdentityModel('apple', 's', 'X@PrivateRelay.AppleID.com', true))->isPrivateRelay(),
        );
        self::assertFalse(
            (new OAuthIdentityModel('apple', 's', 'x@privaterelay.appleid.com.evil.test', true))->isPrivateRelay(),
        );
    }

    /** Pins the '@' anchor the suffix test above cannot: Apple mints relay addresses on the bare domain only. */
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
                (new OAuthIdentityModel('apple', 's', $email, true))->isPrivateRelay(),
                $email . ' must not be read as a private relay address',
            );
        }
    }

    public function testABlankAddressIsTreatedAsAbsentAndIsNotLinkable(): void
    {
        foreach (['', '   ', "\t\n"] as $blank) {
            $identity = new OAuthIdentityModel('google', 'sub-1', $blank, true);

            self::assertNull($identity->email);
            self::assertFalse($identity->isLinkableByEmail());
            self::assertFalse($identity->isPrivateRelay());
        }
    }
}
