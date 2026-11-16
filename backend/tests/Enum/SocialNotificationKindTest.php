<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\SocialNotificationKind;
use PHPUnit\Framework\TestCase;

final class SocialNotificationKindTest extends TestCase
{
    public function testOnlyLikeRepostFollowPollGroup(): void
    {
        $grouping = array_map(
            static fn (SocialNotificationKind $kind): bool => $kind->groups(),
            SocialNotificationKind::cases(),
        );

        self::assertSame([true, true, false, false, false, true, true], $grouping);
    }
}
