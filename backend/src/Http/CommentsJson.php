<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Comments\CommentsResult;
use App\Service\Comments\CommentsStatus;
use App\Service\Comments\EntryComment;

final class CommentsJson
{
    /** @return array<string, mixed> */
    public static function one(CommentsResult $result): array
    {
        return ['status' => $result->status->value] + match ($result->status) {
            CommentsStatus::Ok => ['comments' => array_map(self::comment(...), $result->comments)],
            CommentsStatus::Throttled => ['retryAfter' => $result->retryAfter],
            CommentsStatus::Failed => [],
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
