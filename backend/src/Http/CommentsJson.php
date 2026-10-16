<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Service\Comments\Model\CommentsResultModel;
use App\Service\Comments\Model\CommentsStatus;
use App\Service\Comments\Model\EntryCommentModel;

final class CommentsJson
{
    /** @return array<string, mixed> */
    public static function one(CommentsResultModel $result): array
    {
        return ['status' => $result->status->value] + match ($result->status) {
            CommentsStatus::Ok => [
                'comments' => array_map(self::comment(...), $result->comments),
                'omitted' => $result->omitted,
                'fetchedAt' => $result->fetchedAt?->format(\DateTimeInterface::ATOM),
            ],
            CommentsStatus::Throttled => ['retryAfter' => $result->retryAfter],
            CommentsStatus::Failed => [],
        };
    }

    /** @return array<string, mixed> */
    private static function comment(EntryCommentModel $comment): array
    {
        return [
            'author' => $comment->author->name,
            'authorHandle' => $comment->author->handle,
            'authorUrl' => $comment->author->profileUrl,
            'authorAvatarUrl' => $comment->author->avatarUrl,
            'url' => $comment->url,
            'publishedAt' => $comment->publishedAt?->format(\DateTimeInterface::ATOM),
            'html' => $comment->html,
            'byEntryAuthor' => $comment->byEntryAuthor,
            'depth' => $comment->depth,
            'media' => EntryMedia::toJsonList($comment->embeds->media),
            'linkCard' => $comment->embeds->linkCard === null ? null : LinkCardJson::of($comment->embeds->linkCard),
            'quote' => QuotedPostJson::of($comment->embeds->quote),
        ];
    }
}
