<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Enum\VoteScore;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\LemmyTargetModel;
use App\Service\Social\Social\Model\PostPreviewModel;

final class LemmyView
{
    private const int EXCERPT_LENGTH = 200;
    private const string MISSING = 'The Lemmy instance gave no such post.';

    private function __construct()
    {
    }

    /** @throws SocialPostNotFoundException */
    public static function ofPost(JsonNodeModel $postView): LemmyTargetModel
    {
        $post = $postView->node('post');
        $postId = $post->int('id') ?? throw new SocialPostNotFoundException(self::MISSING);

        return self::target($postView, $postId, null, $post, $post->string('name'));
    }

    /** @throws SocialPostNotFoundException */
    public static function ofComment(JsonNodeModel $commentView): LemmyTargetModel
    {
        $comment = $commentView->node('comment');
        $commentId = $comment->int('id') ?? throw new SocialPostNotFoundException(self::MISSING);
        $postId = $comment->int('post_id') ?? throw new SocialPostNotFoundException(self::MISSING);

        return self::target($commentView, $postId, $commentId, $commentView->node('post'), $comment->text('content'));
    }

    private static function target(
        JsonNodeModel $view,
        int $postId,
        ?int $commentId,
        JsonNodeModel $post,
        ?string $text,
    ): LemmyTargetModel {
        $creator = $view->node('creator');
        $name = $creator->string('name');
        $host = parse_url($creator->string('actor_id') ?? '', \PHP_URL_HOST);
        $identity = null !== $name && \is_string($host) ? mb_strtolower($name . '@' . $host) : null;

        return new LemmyTargetModel(
            $postId,
            $commentId,
            VoteScore::ofNetworkValue($view->int('my_vote')),
            $view->node('counts')->int('score') ?? 0,
            $post->isTrue('locked'),
            $identity,
            new PostPreviewModel(
                $creator->string('display_name') ?? $name,
                null === $identity ? null : '@' . $identity,
                mb_substr($text ?? '', 0, self::EXCERPT_LENGTH),
            ),
        );
    }
}
