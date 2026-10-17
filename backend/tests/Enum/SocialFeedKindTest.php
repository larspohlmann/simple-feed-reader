<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SocialFeedKind;
use PHPUnit\Framework\TestCase;

final class SocialFeedKindTest extends TestCase
{
    public function testOnlyAnAuthorFeedFollowsOnePerson(): void
    {
        $followingOnePerson = array_filter(
            SocialFeedKind::cases(),
            static fn (SocialFeedKind $kind): bool => $kind->followsOnePerson(),
        );

        self::assertSame([SocialFeedKind::Author], array_values($followingOnePerson));
    }
}
