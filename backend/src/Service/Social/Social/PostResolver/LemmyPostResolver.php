<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostResolver;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Content\Comments\Model\LemmyPostModel;
use App\Service\Foundation\Url\Support\LemmyPostUrl;
use App\Service\Social\Social\LemmyPosts;
use App\Service\Social\Social\Model\LemmyTargetModel;
use App\Service\Social\Social\Model\ResponseContextModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Asks the account's own Lemmy instance for a post page or a comment, so either gets the ids it acts on. */
#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPostResolver implements PostResolverInterface
{
    public function __construct(private LemmyPosts $posts)
    {
    }

    public function resolve(SocialAccount $account, string $address): SocialPostModel
    {
        $page = LemmyPostUrl::parse($address);
        $target = null === $page
            ? $this->posts->commentOn($account, $address)
            : $this->posts->postOn($account, new LemmyPostModel('https://' . $page->host, $page->postId));

        return SocialPostModel::onLemmy(
            $account,
            (string) $target->postId,
            null === $target->commentId ? null : (string) $target->commentId,
        )->inResponseContext(self::responseContextOf($target));
    }

    private static function responseContextOf(LemmyTargetModel $target): ResponseContextModel
    {
        return new ResponseContextModel(
            authorIdentity: $target->authorIdentity,
            canReply: !$target->isLocked,
            canQuote: false,
            preview: $target->preview,
        );
    }
}
