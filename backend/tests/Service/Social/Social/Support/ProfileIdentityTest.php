<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\ProfileIdentity;
use PHPUnit\Framework\TestCase;

final class ProfileIdentityTest extends TestCase
{
    public function testAnAccountIdentitySplitsIntoItsNameAndHost(): void
    {
        self::assertSame(['bob', 'mastodon.example'], ProfileIdentity::parseNameAndHost('bob@mastodon.example'));
        self::assertNull(ProfileIdentity::parseNameAndHost('bob'));
        self::assertNull(ProfileIdentity::parseNameAndHost('bob@a.example@b.example'));
    }

    public function testARoundTripIgnoresCaseAndNeedsAParsedIdentity(): void
    {
        self::assertSame('u', ProfileIdentity::parseRoundTripUrl('u', 'bob.bsky.social', 'Bob.bsky.social'));
        self::assertNull(ProfileIdentity::parseRoundTripUrl('u', 'bob.bsky.social', 'carol.bsky.social'));
        self::assertNull(ProfileIdentity::parseRoundTripUrl('u', null, ''));
    }
}
