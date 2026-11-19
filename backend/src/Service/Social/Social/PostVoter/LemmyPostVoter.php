<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostVoter;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Enum\VoteScore;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\LemmyPosts;
use App\Service\Social\Social\Model\DiscussionVoteResultModel;
use App\Service\Social\Social\Model\LemmyTargetModel;
use App\Service\Social\Social\Support\LemmyView;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPostVoter implements PostVoterInterface
{
    public function __construct(
        private LemmyPosts $posts,
        private LemmyAccountApi $accountApi,
        private ActingAccountGuard $guard,
    ) {
    }

    public function isVotablePost(Entry $entry): bool
    {
        return null !== LemmyPostAddress::of($entry);
    }

    public function castVoteOnPost(SocialAccount $account, Entry $entry, VoteScore $score): DiscussionVoteResultModel
    {
        $post = self::requirePost($entry);

        return $this->guard->actAs($account, function () use ($account, $post, $score): DiscussionVoteResultModel {
            $answer = $this->accountApi->post(
                $account,
                '/api/v3/post/like',
                ['post_id' => $this->posts->homePostIdOf($account, $post), 'score' => $score->value],
            );

            return self::buildResult(LemmyView::ofPost(JsonNodeModel::of($answer->json())->node('post_view')));
        });
    }

    public function castVoteOnComment(
        SocialAccount $account,
        string $address,
        VoteScore $score,
    ): DiscussionVoteResultModel {
        return $this->guard->actAs($account, function () use ($account, $address, $score): DiscussionVoteResultModel {
            $target = $this->posts->commentOn($account, $address);
            $answer = $this->accountApi->post(
                $account,
                '/api/v3/comment/like',
                ['comment_id' => $target->commentId, 'score' => $score->value],
            );

            return self::buildResult(LemmyView::ofComment(JsonNodeModel::of($answer->json())->node('comment_view')));
        });
    }

    public function fetchPostVote(SocialAccount $account, Entry $entry): VoteScore
    {
        $post = self::requirePost($entry);

        return $this->guard->actAs($account, fn (): VoteScore => $this->posts->postOn($account, $post)->myVote);
    }

    /** @throws NotASocialPostException */
    private static function requirePost(Entry $entry): LemmyPostModel
    {
        return LemmyPostAddress::of($entry) ?? throw new NotASocialPostException('This entry is not a Lemmy post.');
    }

    private static function buildResult(LemmyTargetModel $target): DiscussionVoteResultModel
    {
        return new DiscussionVoteResultModel($target->myVote, $target->score);
    }
}
