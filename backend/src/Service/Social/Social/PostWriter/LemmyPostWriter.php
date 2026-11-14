<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostWriter;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\ComposerDefaults\LemmyComposerDefaults;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\LemmyAccountApi;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPostWriter implements PostWriterInterface
{
    private const string NO_QUOTES = 'Lemmy takes no quotes or link posts.';

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
        throw new SocialPostRefusedException(self::NO_QUOTES);
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        throw new SocialPostRefusedException('Deleting a Lemmy comment is not supported here.');
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
