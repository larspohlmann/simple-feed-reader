<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class SubstackPostModel
{
    public function __construct(public string $origin, public string $slug)
    {
    }

    public function url(): string
    {
        return $this->origin . '/p/' . $this->slug;
    }

    public function postApiUrl(): string
    {
        return $this->origin . '/api/v1/posts/' . $this->slug;
    }

    public function commentsApiUrl(int $postId): string
    {
        return $this->origin . '/api/v1/post/' . $postId . '/comments?all_comments=true&sort=best_first';
    }

    public function commentUrl(int $commentId): string
    {
        return $this->url() . '/comment/' . $commentId;
    }
}
