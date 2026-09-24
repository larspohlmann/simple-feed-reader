<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Comments\CommentsResult;
use App\Service\Comments\EntryComment;

final class CommentsJson
{
    /** @return array<string, mixed> */
    public static function one(CommentsResult $result, ?string $discussionUrl): array
    {
        return match ($result->status) {
            'ok' => [
                'status' => 'ok',
                'discussionUrl' => $discussionUrl,
                'comments' => array_map(self::comment(...), $result->comments),
            ],
            'throttled' => [
                'status' => 'throttled',
                'discussionUrl' => $discussionUrl,
                'retryAfter' => $result->retryAfter,
            ],
            default => ['status' => 'failed', 'discussionUrl' => $discussionUrl],
        };
    }

    /** @return array<string, string|bool|null> */
    private static function comment(EntryComment $comment): array
    {
        return [
            'author' => $comment->author,
            'authorUrl' => $comment->authorUrl,
            'url' => $comment->url,
            'publishedAt' => $comment->publishedAt?->format(\DateTimeInterface::ATOM),
            'html' => $comment->html,
            'byEntryAuthor' => $comment->byEntryAuthor,
        ];
    }
}
