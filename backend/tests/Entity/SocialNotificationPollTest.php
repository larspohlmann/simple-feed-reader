<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SocialNotificationPoll;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class SocialNotificationPollTest extends TestCase
{
    public function testANewPollHasNeverBeenChecked(): void
    {
        $poll = new SocialNotificationPoll(SocialAccounts::mastodon());

        self::assertNull($poll->getCheckedAt());
        self::assertFalse($poll->isScopeMissing());
    }

    public function testCheckedClearsAnEarlierScopeFlag(): void
    {
        $poll = new SocialNotificationPoll(SocialAccounts::mastodon());
        $poll->recordScopeMissing(new \DateTimeImmutable('2026-11-17 10:00'));
        self::assertTrue($poll->isScopeMissing());

        $poll->recordChecked(new \DateTimeImmutable('2026-11-17 10:05'));

        self::assertFalse($poll->isScopeMissing());
        self::assertEquals(new \DateTimeImmutable('2026-11-17 10:05'), $poll->getCheckedAt());
    }

    public function testScopeMissingStampsTheCheck(): void
    {
        $poll = new SocialNotificationPoll(SocialAccounts::mastodon());

        $poll->recordScopeMissing(new \DateTimeImmutable('2026-11-17 10:00'));

        self::assertTrue($poll->isScopeMissing());
        self::assertEquals(new \DateTimeImmutable('2026-11-17 10:00'), $poll->getCheckedAt());
    }
}
