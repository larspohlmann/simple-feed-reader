<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Enum\VoteScore;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyPosts;
use App\Service\Social\Lemmy\Model\LemmyTargetModel;
use App\Service\Social\Lemmy\Support\LemmyPostKey;
use App\Service\Social\Lemmy\Support\LemmyView;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Model\DiscussionVoteResultModel;
use App\Service\Social\Social\PostVoter\PostVoterInterface;
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
        return null !== LemmyPostAddress::findPost($entry);
    }

    public function castVoteOnPost(SocialAccount $account, Entry $entry, VoteScore $score): DiscussionVoteResultModel
    {
        $post = self::requirePost($entry);

        return $this->guard->actAs($account, function () use ($account, $post, $score): DiscussionVoteResultModel {
            $answer = $this->accountApi->post(
                $account,
                '/api/v3/post/like',
                ['post_id' => $this->posts->resolveHomePostId($account, $post), 'score' => $score->value],
            );

            return self::buildPostResult(
                $account,
                LemmyView::fromPostView(JsonNodeModel::fromValue($answer->json())->node('post_view')),
            );
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

            $comment = JsonNodeModel::fromValue($answer->json())->node('comment_view');

            return self::buildCommentResult(LemmyView::fromCommentView($comment));
        });
    }

    public function fetchPostVoteResult(SocialAccount $account, Entry $entry): DiscussionVoteResultModel
    {
        $post = self::requirePost($entry);

        return $this->guard->actAs(
            $account,
            fn (): DiscussionVoteResultModel => self::buildPostResult($account, $this->posts->postOn($account, $post)),
        );
    }

    /** @throws NotASocialPostException */
    private static function requirePost(Entry $entry): LemmyPostModel
    {
        return LemmyPostAddress::findPost($entry)
            ?? throw new NotASocialPostException('This entry is not a Lemmy post.');
    }

    private static function buildPostResult(SocialAccount $account, LemmyTargetModel $post): DiscussionVoteResultModel
    {
        $homeUrl = LemmyPostKey::buildPostPage($account->getServiceUrl(), $post->postId);

        return new DiscussionVoteResultModel($post->myVote, $post->score, $homeUrl);
    }

    private static function buildCommentResult(LemmyTargetModel $comment): DiscussionVoteResultModel
    {
        return new DiscussionVoteResultModel($comment->myVote, $comment->score);
    }
}
