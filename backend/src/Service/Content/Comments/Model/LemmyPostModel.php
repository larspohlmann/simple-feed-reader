<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Model;

final readonly class LemmyPostModel
{
    public function __construct(public string $instanceUrl, public int $postId)
    {
    }

    public function pageUrl(): string
    {
        return $this->instanceUrl . '/post/' . $this->postId;
    }

    public function commentsApiUrl(): string
    {
        return $this->instanceUrl . self::commentsApiPath($this->postId);
    }

    public static function commentsApiPath(int $postId): string
    {
        return '/api/v3/comment/list?post_id=' . $postId . '&sort=Hot&type_=All&max_depth=8&limit=50';
    }
}
