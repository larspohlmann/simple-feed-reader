<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Bluesky\BlueskySessions;
use App\Service\Social\Bluesky\BlueskyThreadReplies;
use App\Service\Social\Bluesky\Support\AppViewAnswer;
use App\Service\Social\BlueskyPost\AppViewClient;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\ReplyThreadSource\ReplyThreadSourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyReplyThreadSource implements ReplyThreadSourceInterface
{
    private const string GONE = 'The post is no longer on Bluesky.';

    public function __construct(
        private BlueskySessions $sessions,
        private AppViewClient $appView,
        private BlueskyThreadReplies $replies,
        #[Autowire('%social_replies_max_depth%')] private int $maxDepth,
    ) {
    }

    public function asAccount(SocialAccount $account, string $address): CommentsAnswerModel
    {
        $answer = $this->sessions->askAs($account, '/xrpc/' . AppViewClient::threadQuery($address, $this->maxDepth));
        if (self::isMissingPost($answer)) {
            throw new SocialPostNotFoundException(self::GONE);
        }

        return CommentsAnswerModel::settled(BlueskySessions::accepted($answer)->body);
    }

    public function inPublic(string $address): CommentsAnswerModel
    {
        return AppViewAnswer::orSocialFailure(
            $this->publicThreadThrottledForSeconds(),
            fn (): CommentsAnswerModel
                => CommentsAnswerModel::settled($this->appView->thread($address, $this->maxDepth)),
        );
    }

    public function publicThreadThrottledForSeconds(): int
    {
        return $this->appView->throttledForSeconds();
    }

    public function repliesIn(string $address, string $answer): array
    {
        return $this->replies->of($address, $answer);
    }

    public function buildSelfReply(JsonNodeModel $post): EntryCommentModel
    {
        return $this->replies->selfReplyOf($post);
    }

    private static function isMissingPost(ApiResponseModel $answer): bool
    {
        return Response::HTTP_BAD_REQUEST === $answer->status
            && 'NotFound' === JsonNodeModel::fromValue($answer->json())->string('error');
    }
}
