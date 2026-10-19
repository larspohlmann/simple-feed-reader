<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SyncStamp;
use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialFeedKind;
use PHPUnit\Framework\TestCase;

final class SyncStampTest extends TestCase
{
    private const string AT = '2026-10-17 12:00:00';

    public function testANeverCompletedStampOwesEveryRememberedCollectionKind(): void
    {
        self::assertSame(SocialFeedKind::rememberedCollections(), new SyncStamp()->kindsNotYetSynced());
    }

    public function testAStampSyncedBeforeTagsOwesOnlyTags(): void
    {
        $at = new \DateTimeImmutable(self::AT);

        self::assertSame(
            [SocialFeedKind::Tag],
            new SyncStamp($at, $at, null, ['list', 'custom_feed'])->kindsNotYetSynced(),
        );
    }

    public function testACompletedStampOwesNothingAndASkipKeepsThat(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $completed = SyncStamp::completed($at);

        self::assertSame([], $completed->kindsNotYetSynced());
        self::assertSame([], $completed->skipped($at, PrivateFeedSyncSkip::Unreachable)->kindsNotYetSynced());
    }
}
