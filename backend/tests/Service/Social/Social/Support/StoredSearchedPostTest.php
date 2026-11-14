<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Model\StoredPostModel;
use App\Service\Social\Social\Support\StoredSearchedPost;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class StoredSearchedPostTest extends TestCase
{
    public function testALemmyPostIsNeverJudgedByTheReader(): void
    {
        $post = new StoredPostModel(1, 'https://lemmy.example/post/1', '<p>rss</p>', 'https://lemmy.example/u/ada');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A Lemmy search is never matched by the reader.');

        StoredSearchedPost::of(SocialAccounts::lemmyOf(SocialAccounts::owner()), $post);
    }
}
