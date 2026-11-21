<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\NotificationContext;
use App\Entity\NotificationPostKeys;
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

    public function testASubjectStartsWithoutContext(): void
    {
        $subject = new NotificationSubject('k', null, 'u');

        self::assertEquals(new NotificationContext(), $subject->context);
    }

    public function testWithContextKeepsTheRest(): void
    {
        $subject = new NotificationSubject('key', 'text', 'https://example.test/p');
        $context = new NotificationContext('mine', 'Title', 'c/php', 'Ann');

        $changed = $subject->withContext($context);

        self::assertSame('key', $changed->groupKey);
        self::assertSame('text', $changed->excerpt);
        self::assertSame('https://example.test/p', $changed->linkUrl);
        self::assertSame($context, $changed->context);
    }

    public function testASubjectStartsWithoutKeysAndWithKeysKeepsTheRest(): void
    {
        $subject = new NotificationSubject('key', 'text', 'https://example.test/p');
        $keys = new NotificationPostKeys('p', 'a', 'r');

        $changed = $subject->withKeys($keys);

        self::assertEquals(new NotificationPostKeys(), $subject->keys);
        self::assertEquals(new NotificationPostKeys(), NotificationSubject::follow('https://x.test')->keys);
        self::assertSame($keys, $changed->keys);
        self::assertSame('key', $changed->groupKey);
        self::assertSame($subject->context, $changed->context);
    }

    public function testAContextIsChangedOneFieldGroupAtATime(): void
    {
        $context = (new NotificationContext())->withAnsweredExcerpt('mine')->withThread('Title', 'Ann');

        self::assertEquals(new NotificationContext('mine', 'Title', null, 'Ann'), $context);
    }

    public function testAContextIsAboutACommentOnlyWhenMarked(): void
    {
        $context = new NotificationContext('mine', 'Title', 'c/php', 'Ann');

        self::assertFalse($context->onComment);
        self::assertEquals(new NotificationContext('mine', 'Title', 'c/php', 'Ann', true), $context->withOnComment());
    }
}
