<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostWriter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\ComposerDefaults\LemmyComposerDefaults;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostCommunityMissingException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostTitleMissingException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\Model\LemmyLinkModel;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPostWriter implements PostWriterInterface
{
    private const string NO_QUOTES = 'Lemmy takes no quotes.';

    public function __construct(private LemmyAccountApi $lemmy)
    {
    }

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        if (mb_strlen($draft->text) > LemmyComposerDefaults::MAX_CHARACTERS) {
            throw new SocialPostTooLongException(
                sprintf('A Lemmy comment holds %d characters.', LemmyComposerDefaults::MAX_CHARACTERS),
            );
        }
        $answer = $this->lemmy->post($target->account, '/api/v3/comment', self::commentOf($target, $draft));
        $address = AbsoluteHttpUrl::httpsOrNull(
            JsonNodeModel::of($answer->json())->node('comment_view')->node('comment')->string('ap_id'),
        ) ?? throw new SocialNetworkUnreachableException('Lemmy took the comment but named no address for it.');

        return new PublishedPostModel($address, $address);
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        throw new SocialPostRefusedException(self::NO_QUOTES);
    }

    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel
    {
        $post = self::linkPostOf($url, $draft);
        $answer = $this->lemmy->post($account, '/api/v3/post', $post);
        $address = AbsoluteHttpUrl::httpsOrNull(
            JsonNodeModel::of($answer->json())->node('post_view')->node('post')->string('ap_id'),
        ) ?? throw new SocialNetworkUnreachableException('Lemmy took the post but named no address for it.');

        return new PublishedPostModel($address, $address);
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        throw new SocialPostRefusedException('Deleting a Lemmy comment is not supported here.');
    }

    /** @return array<string, string|int> */
    private static function linkPostOf(string $url, PostDraftModel $draft): array
    {
        $communityId = $draft->communityId
            ?? throw new SocialPostCommunityMissingException('A Lemmy link post goes to a community.');
        $title = trim($draft->title ?? '');
        if ('' === $title) {
            throw new SocialPostTitleMissingException('A Lemmy link post needs a title.');
        }
        if (mb_strlen($title) > LemmyLinkModel::MAX_TITLE_CHARACTERS) {
            throw new SocialPostTooLongException(
                sprintf('A Lemmy post title holds %d characters.', LemmyLinkModel::MAX_TITLE_CHARACTERS),
            );
        }
        if (mb_strlen($draft->text) > LemmyComposerDefaults::MAX_CHARACTERS) {
            throw new SocialPostTooLongException(
                sprintf('A Lemmy post holds %d characters.', LemmyComposerDefaults::MAX_CHARACTERS),
            );
        }
        $post = ['name' => $title, 'url' => $url];
        if ('' !== trim($draft->text)) {
            $post['body'] = $draft->text;
        }
        $post['community_id'] = $communityId;

        return $post;
    }

    /** @return array<string, string|int> */
    private static function commentOf(SocialPostModel $target, PostDraftModel $draft): array
    {
        $comment = ['content' => $draft->text, 'post_id' => (int) $target->postId];
        if (null !== $target->commentId) {
            $comment['parent_id'] = (int) $target->commentId;
        }

        return $comment;
    }
}
