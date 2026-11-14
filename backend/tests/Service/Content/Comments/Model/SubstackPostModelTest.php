<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Model;

use App\Service\Comments\Model\SubstackPostModel;
use PHPUnit\Framework\TestCase;

final class SubstackPostModelTest extends TestCase
{
    public function testDerivesThePageTheApiAndTheCommentAddresses(): void
    {
        $post = new SubstackPostModel('https://joe.substack.com', 'one-post');

        self::assertSame('https://joe.substack.com/p/one-post', $post->url());
        self::assertSame('https://joe.substack.com/api/v1/posts/one-post', $post->postApiUrl());
        self::assertSame(
            'https://joe.substack.com/api/v1/post/42/comments?all_comments=true&sort=best_first',
            $post->commentsApiUrl(42),
        );
        self::assertSame('https://joe.substack.com/p/one-post/comment/7', $post->commentUrl(7));
    }
}
