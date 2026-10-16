<?php

declare(strict_types=1);

namespace App\Service\Social\ReplyThreadSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\BlueskySessions;
use App\Service\Social\BlueskyThreadReplies;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskyReplyThreadSource implements ReplyThreadSourceInterface
{
    private const string THROTTLED = "Bluesky's AppView asked for fewer requests.";
    private const string GONE = 'The post is no longer on Bluesky.';

    public function __construct(
        private BlueskySessions $sessions,
        private AppViewClient $appView,
        private BlueskyThreadReplies $replies,
        #[Autowire('%social_replies_max_depth%')] private int $maxDepth,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
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
        $throttledForSeconds = $this->publicThreadThrottledForSeconds();
        if ($throttledForSeconds > 0) {
            throw new SocialNetworkThrottledException(self::THROTTLED, $throttledForSeconds);
        }

        try {
            return CommentsAnswerModel::settled($this->appView->thread($address, $this->maxDepth));
        } catch (FeedThrottledException $exception) {
            throw new SocialNetworkThrottledException(self::THROTTLED, $exception->retryAfterSeconds, $exception);
        } catch (FetchException | AppViewAnswerException $exception) {
            throw new SocialNetworkUnreachableException(
                "Bluesky's AppView could not be reached.",
                previous: $exception,
            );
        }
    }

    public function publicThreadThrottledForSeconds(): int
    {
        return $this->appView->throttledForSeconds();
    }

    public function repliesIn(string $address, string $answer): array
    {
        return $this->replies->of($address, $answer);
    }

    private static function isMissingPost(ApiResponseModel $answer): bool
    {
        return Response::HTTP_BAD_REQUEST === $answer->status
            && 'NotFound' === JsonNodeModel::of($answer->json())->string('error');
    }
}
