<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Model;

use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\EntryCommentModel;
use PHPUnit\Framework\TestCase;

final class EntryCommentModelTest extends TestCase
{
    public function testAtDepthKeepsEverythingButTheDepth(): void
    {
        $comment = new EntryCommentModel(new CommentAuthorModel('Ann'), 'https://x.test/1', null, '<p>Hi</p>', true);

        $nested = $comment->atDepth(3);

        self::assertSame(3, $nested->depth);
        self::assertSame(0, $comment->depth);
        self::assertEquals($comment->author, $nested->author);
        self::assertSame('<p>Hi</p>', $nested->html);
        self::assertTrue($nested->byEntryAuthor);
    }
}
