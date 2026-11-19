<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\LemmyComposerDefaults;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostCommunityMissingException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Exception\SocialPostTitleTooShortException;
use App\Service\Social\Social\Exception\SocialPostTooLongException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\PostDraftModel;
use App\Service\Social\Social\Model\PublishedPostModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\PostWriter\PostWriterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPostWriter implements PostWriterInterface
{
    public const int MIN_TITLE_CHARACTERS = 3;
    public const int MAX_TITLE_CHARACTERS = 200;

    private const string NO_QUOTES = 'Lemmy takes no quotes.';

    public function __construct(private LemmyAccountApi $lemmy)
    {
    }

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        self::guardLength($draft->text, LemmyComposerDefaults::MAX_CHARACTERS, 'A Lemmy comment holds %d characters.');
        $answer = $this->lemmy->post($target->account, '/api/v3/comment', self::commentOf($target, $draft));

        return self::publishedAt($answer, 'comment_view', 'comment');
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        throw new SocialPostRefusedException(self::NO_QUOTES);
    }

    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel
    {
        $answer = $this->lemmy->post($account, '/api/v3/post', self::linkPostOf($url, $draft));

        return self::publishedAt($answer, 'post_view', 'post');
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
        $title = self::titleOf($draft);
        self::guardLength($draft->text, LemmyComposerDefaults::MAX_CHARACTERS, 'A Lemmy post holds %d characters.');

        return ['name' => $title, 'url' => $url]
            + ('' === trim($draft->text) ? [] : ['body' => $draft->text])
            + ['community_id' => $communityId];
    }

    private static function titleOf(PostDraftModel $draft): string
    {
        $title = trim($draft->title ?? '');
        if (mb_strlen($title) < self::MIN_TITLE_CHARACTERS) {
            throw new SocialPostTitleTooShortException(
                sprintf('A Lemmy title needs at least %d characters.', self::MIN_TITLE_CHARACTERS),
            );
        }
        self::guardLength(
            $title,
            self::MAX_TITLE_CHARACTERS,
            'A Lemmy post title holds %d characters.',
        );

        return $title;
    }

    private static function guardLength(string $text, int $maxCharacters, string $limitMessage): void
    {
        if (mb_strlen($text) > $maxCharacters) {
            throw new SocialPostTooLongException(sprintf($limitMessage, $maxCharacters));
        }
    }

    private static function publishedAt(ApiResponseModel $answer, string $view, string $object): PublishedPostModel
    {
        $address = AbsoluteHttpUrl::httpsOrNull(
            JsonNodeModel::of($answer->json())->node($view)->node($object)->string('ap_id'),
        ) ?? throw new SocialNetworkUnreachableException(
            sprintf('Lemmy took the %s but named no address for it.', $object),
        );

        return new PublishedPostModel($address, $address);
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
