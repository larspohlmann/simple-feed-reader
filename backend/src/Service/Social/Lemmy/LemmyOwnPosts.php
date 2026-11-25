<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Lemmy\Model\LemmyPostAttemptModel;
use App\Service\Social\Notification\Support\NetworkTimestamp;
use App\Service\Social\Publishing\Model\PublishedPostModel;

final readonly class LemmyOwnPosts
{
    private const int RECENT_WINDOW_SECONDS = 120;
    private const int LOOKUP_LIMIT = 10;

    public function __construct(private LemmyAccountApi $lemmy)
    {
    }

    public function findJustPublished(SocialAccount $account, LemmyPostAttemptModel $attempt): ?PublishedPostModel
    {
        $answer = $this->lemmy->get($account, sprintf(
            '/api/v3/user?username=%s&sort=New&limit=%d',
            rawurlencode($account->getIdentity()),
            self::LOOKUP_LIMIT,
        ));
        $earliest = $attempt->sentAt->modify(sprintf('-%d seconds', self::RECENT_WINDOW_SECONDS));

        foreach (JsonNodeModel::fromValue($answer->json())->nodes('posts') as $view) {
            $address = $this->findMatchingAddress($view->node('post'), $attempt, $earliest);
            if (null !== $address) {
                return new PublishedPostModel($address, $address);
            }
        }

        return null;
    }

    private function findMatchingAddress(
        JsonNodeModel $post,
        LemmyPostAttemptModel $attempt,
        \DateTimeImmutable $earliest,
    ): ?string {
        if (
            $post->string('name') !== $attempt->title
            || $post->int('community_id') !== $attempt->communityId
            || $post->isTrue('deleted')
            || !$this->isPublishedSince($post, $earliest)
        ) {
            return null;
        }

        return AbsoluteHttpUrl::keepIfHttps($post->string('ap_id'));
    }

    private function isPublishedSince(JsonNodeModel $post, \DateTimeImmutable $earliest): bool
    {
        $published = NetworkTimestamp::toUtc($post->string('published'));

        return null !== $published && $published >= $earliest;
    }
}
