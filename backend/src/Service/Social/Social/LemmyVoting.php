<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Enum\VoteScore;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\LemmyTargetModel;
use App\Service\Social\Social\Support\LemmyView;

/** Casts and reads a vote on a post or comment through the account's own Lemmy instance. */
final readonly class LemmyVoting
{
    public function __construct(
        private LemmyPosts $posts,
        private LemmyAccountApi $accountApi,
        private ActingAccountGuard $guard,
    ) {
    }

    /**
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function castOnPost(SocialAccount $account, LemmyPostModel $post, VoteScore $score): LemmyTargetModel
    {
        return $this->guard->actAs($account, function () use ($account, $post, $score): LemmyTargetModel {
            $answer = $this->accountApi->post(
                $account,
                '/api/v3/post/like',
                ['post_id' => $this->posts->homePostIdOf($account, $post), 'score' => $score->value],
            );

            return LemmyView::ofPost(JsonNodeModel::of($answer->json())->node('post_view'));
        });
    }

    /**
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function castOnComment(SocialAccount $account, string $address, VoteScore $score): LemmyTargetModel
    {
        return $this->guard->actAs($account, function () use ($account, $address, $score): LemmyTargetModel {
            $target = $this->posts->commentOn($account, $address);
            $answer = $this->accountApi->post(
                $account,
                '/api/v3/comment/like',
                ['comment_id' => $target->commentId, 'score' => $score->value],
            );

            return LemmyView::ofComment(JsonNodeModel::of($answer->json())->node('comment_view'));
        });
    }

    /**
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialPostRefusedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function readPost(SocialAccount $account, LemmyPostModel $post): LemmyTargetModel
    {
        return $this->guard->actAs($account, fn (): LemmyTargetModel => $this->posts->postOn($account, $post));
    }
}
