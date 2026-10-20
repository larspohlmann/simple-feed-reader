<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\EntryMedia;
use App\Service\Comments\Model\CommentPostModel;
use App\Service\Comments\Model\CommentsResultModel;
use App\Service\Comments\Model\CommentsStatus;
use App\Service\Comments\Model\EntryCommentModel;

final readonly class CommentsJson
{
    public function __construct(
        private LinkCardJson $linkCardJson,
    ) {
    }

    /** @return array<string, mixed> */
    public function one(CommentsResultModel $result): array
    {
        return ['status' => $result->status->value] + match ($result->status) {
            CommentsStatus::Ok => [
                'comments' => array_map($this->comment(...), $result->comments),
                'omitted' => $result->omitted,
                'fetchedAt' => $result->fetchedAt?->format(\DateTimeInterface::ATOM),
            ],
            CommentsStatus::Throttled => ['retryAfter' => $result->retryAfter],
            CommentsStatus::Failed => [],
        };
    }

    /** @return array<string, mixed> */
    private function comment(EntryCommentModel $comment): array
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
            'linkCard' => $this->linkCardJson->ofOrNull($comment->embeds->linkCard),
            'quote' => QuotedPostJson::ofOrNull($comment->embeds->quote),
            'post' => null === $comment->post ? null : self::post($comment->post),
        ];
    }

    /** @return array{address: string, counts: array{likes: int, reposts: int, replies: int, quotes: int|null}, isLiked: bool} */
    private static function post(CommentPostModel $post): array
    {
        return [
            'address' => $post->address,
            'counts' => SocialCountsJson::ofReply($post->counts),
            'isLiked' => $post->isLiked,
        ];
    }
}
