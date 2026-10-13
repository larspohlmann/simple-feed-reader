<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\BlueskySessions;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\BlueskyLikeUri;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskyPostLiker implements PostLikerInterface
{
    private const string CREATE_RECORD = '/xrpc/com.atproto.repo.createRecord';
    private const string DELETE_RECORD = '/xrpc/com.atproto.repo.deleteRecord';
    private const string LIKE_COLLECTION = 'app.bsky.feed.like';
    private const array LIKE_GONE = [Response::HTTP_BAD_REQUEST, Response::HTTP_NOT_FOUND];

    public function __construct(
        private BlueskySessions $sessions,
        private ClockInterface $clock,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        if (null !== $post->existingLikeId) {
            return new SocialLikeResultModel($post->existingLikeId);
        }
        $answer = $this->sessions->postAs($post->account, self::CREATE_RECORD, [
            'repo' => $post->account->getIdentity(),
            'collection' => self::LIKE_COLLECTION,
            'record' => [
                '$type' => self::LIKE_COLLECTION,
                'subject' => ['uri' => $post->postId, 'cid' => $post->contentId],
                'createdAt' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            ],
        ]);

        if (Response::HTTP_BAD_REQUEST === $answer->status && !BlueskySessions::isStale($answer)) {
            throw new SocialPostRefusedException('Bluesky refused this like on the post.');
        }
        $createdUri = JsonNodeModel::of(BlueskySessions::accepted($answer)->json())->string('uri');
        $likeId = BlueskyLikeUri::orNull($createdUri)
            ?? throw new SocialNetworkUnreachableException('Bluesky did not name the like it created.');

        return new SocialLikeResultModel($likeId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $answer = $this->sessions->postAs($account, self::DELETE_RECORD, [
            'repo' => $account->getIdentity(),
            'collection' => self::LIKE_COLLECTION,
            'rkey' => substr((string) strrchr($likeId, '/'), 1),
        ]);
        if (\in_array($answer->status, self::LIKE_GONE, true) && !BlueskySessions::isStale($answer)) {
            return;
        }
        BlueskySessions::accepted($answer);
    }
}
