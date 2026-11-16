<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Model\StoredPostModel;
use App\Service\Social\Social\Support\SocialSearchMatch;
use App\Service\Social\Social\Support\StoredSearchedPost;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class StoredSearchedPostTest extends TestCase
{
    public function testALemmyPostIsJudgedByTitleAndBody(): void
    {
        $post = new StoredPostModel(
            1,
            'https://lemmy.example/post/1',
            'rss reader',
            '<p>atom</p>',
            'https://lemmy.example/u/ada',
        );

        $searched = StoredSearchedPost::of(SocialAccounts::lemmyOf(SocialAccounts::owner()), $post);

        self::assertTrue(SocialSearchMatch::matcher('reader atom')($searched));
    }
}
