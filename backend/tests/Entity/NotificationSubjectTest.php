<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\NotificationSubject;
use PHPUnit\Framework\TestCase;

final class NotificationSubjectTest extends TestCase
{
    public function testAFollowHasNoPostOnlyTheProfileToLinkTo(): void
    {
        $subject = NotificationSubject::follow('https://bsky.app/profile/did:plc:ivy');

        self::assertSame('', $subject->groupKey);
        self::assertNull($subject->excerpt);
        self::assertSame('https://bsky.app/profile/did:plc:ivy', $subject->linkUrl);
    }
}
