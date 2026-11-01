<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Model;

use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Comments\Model\CommentPostModel;
use App\Service\Comments\Model\CommentViewerModel;
use PHPUnit\Framework\TestCase;

final class CommentPostModelTest extends TestCase
{
    public function testOwningAPostKeepsEverythingElseTheViewerKnew(): void
    {
        $counts = new CommentCountsModel(1, 2, 3, null);
        $viewer = new CommentViewerModel(true, false, true, canReply: false);
        $post = new CommentPostModel('at://x', $counts, $viewer, 'did:x');

        $owned = $post->ownedByViewer();

        self::assertEquals(
            new CommentPostModel('at://x', $counts, new CommentViewerModel(true, false, true, false, true), 'did:x'),
            $owned,
        );
        self::assertFalse($post->viewer->isOwn);
    }
}
