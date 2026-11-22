<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Social\Lemmy\LemmyAccountApi;
use App\Service\Social\Lemmy\Support\LemmyPostLimits;
use App\Service\Social\Publishing\Exception\SocialPostCommunityMissingException;
use App\Service\Social\Publishing\Exception\SocialPostTitleTooShortException;
use App\Service\Social\Publishing\Exception\SocialPostTooLongException;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\PostEmbedModelInterface;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\PostWriter\PostWriterInterface;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\PostDeleter\PostDeleterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPostWriter implements PostWriterInterface, PostDeleterInterface
{
    private const string NO_QUOTES = 'Lemmy takes no quotes.';

    public function __construct(private LemmyAccountApi $lemmy)
    {
    }

    public function publish(SocialAccount $account, PublicationModel $publication): PublishedPostModel
    {
        if ($publication->embed instanceof QuotedPostEmbedModel) {
            throw new SocialPostRefusedException(self::NO_QUOTES);
        }
        if (null !== $publication->inReplyTo) {
            return $this->publishComment($account, $publication->inReplyTo, $publication->draft);
        }

        $answer = $this->lemmy->post($account, '/api/v3/post', self::buildPostPayload($publication));

        return self::requirePublishedPost($answer, 'post_view', 'post');
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        throw new SocialPostRefusedException('Deleting a Lemmy comment is not supported here.');
    }

    private function publishComment(
        SocialAccount $account,
        SocialPostModel $target,
        PostDraftModel $draft,
    ): PublishedPostModel {
        self::guardLength($draft->text, LemmyPostLimits::MAX_CHARACTERS, 'A Lemmy comment holds %d characters.');
        $answer = $this->lemmy->post($account, '/api/v3/comment', self::buildCommentPayload($target, $draft));

        return self::requirePublishedPost($answer, 'comment_view', 'comment');
    }

    /** @return array<string, string|int> */
    private static function buildPostPayload(PublicationModel $publication): array
    {
        $text = $publication->draft->text;
        $communityId = $publication->lemmy->communityId
            ?? throw new SocialPostCommunityMissingException('A Lemmy link post goes to a community.');
        $title = self::requireValidTitle($publication->lemmy->title);
        self::guardLength($text, LemmyPostLimits::MAX_CHARACTERS, 'A Lemmy post holds %d characters.');

        return ['name' => $title]
            + self::buildUrlField($publication->embed)
            + ('' === trim($text) ? [] : ['body' => $text])
            + ['community_id' => $communityId];
    }

    /** @return array{url?: string} */
    private static function buildUrlField(?PostEmbedModelInterface $embed): array
    {
        return $embed instanceof LinkEmbedModel ? ['url' => $embed->url] : [];
    }

    private static function requireValidTitle(?string $title): string
    {
        $title = trim($title ?? '');
        if (mb_strlen($title) < LemmyPostLimits::MIN_TITLE_CHARACTERS) {
            throw new SocialPostTitleTooShortException(
                sprintf('A Lemmy title needs at least %d characters.', LemmyPostLimits::MIN_TITLE_CHARACTERS),
            );
        }
        self::guardLength(
            $title,
            LemmyPostLimits::MAX_TITLE_CHARACTERS,
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

    private static function requirePublishedPost(
        ApiResponseModel $answer,
        string $view,
        string $object,
    ): PublishedPostModel {
        $address = AbsoluteHttpUrl::keepIfHttps(
            JsonNodeModel::fromValue($answer->json())->node($view)->node($object)->string('ap_id'),
        ) ?? throw new SocialNetworkUnreachableException(
            sprintf('Lemmy took the %s but named no address for it.', $object),
        );

        return new PublishedPostModel($address, $address);
    }

    /** @return array<string, string|int> */
    private static function buildCommentPayload(SocialPostModel $target, PostDraftModel $draft): array
    {
        $comment = ['content' => $draft->text, 'post_id' => (int) $target->postId];
        if (null !== $target->commentId) {
            $comment['parent_id'] = (int) $target->commentId;
        }

        return $comment;
    }
}
