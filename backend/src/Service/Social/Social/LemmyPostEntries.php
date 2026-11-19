<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Discussion;
use App\Entity\DiscussionCounts;
use App\Entity\PostCommunity;
use App\Entity\SocialAccount;
use App\Service\Foundation\Text\MarkdownHtml;
use App\Service\Foundation\Text\Support\HtmlAnchor;
use App\Service\Foundation\Url\Support\LemmyPostUrl;
use App\Service\Ingestion\Parser\Model\ParsedEntryMediaModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Support\DateParser;
use App\Service\Ingestion\Parser\Support\EntryTitle;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Image\Model\DeclaredImageModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Completion\Model\KnownSharesModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\Support\LemmyCommunity;
use App\Service\Social\Social\Support\LemmyLanguageTag;
use App\Service\Social\Social\Support\LemmySearchedPost;
use App\Service\Social\Social\Support\SocialSearchMatch;

final readonly class LemmyPostEntries
{
    public function __construct(private MarkdownHtml $markdown)
    {
    }

    /** @throws SocialNetworkUnreachableException */
    public function page(SocialAccount $account, ApiResponseModel $answer): TimelinePageModel
    {
        $postViews = self::postViewsOf($answer);

        $entries = $this->entriesOf($postViews, $account);

        return TimelinePageModel::of($entries, KnownSharesModel::none(), self::newestIdOf($postViews));
    }

    /**
     * @param list<ApiResponseModel> $answers one per asked term
     *
     * @throws SocialNetworkUnreachableException
     */
    public function searchPage(SocialAccount $account, array $answers, string $query): TimelinePageModel
    {
        $postViews = self::distinctPostViewsOf($answers);
        $matches = SocialSearchMatch::matcher($query);
        $entries = array_values(array_filter(
            $this->entriesOf($postViews, $account),
            static fn (ParsedEntryModel $entry): bool => $matches(
                LemmySearchedPost::of($entry->headline->text, $entry->contentHtml, $entry->authorUrl),
            ),
        ));

        return TimelinePageModel::of($entries, KnownSharesModel::none(), self::newestIdOf($postViews));
    }

    /**
     * @return list<JsonNodeModel>
     *
     * @throws SocialNetworkUnreachableException
     */
    private static function postViewsOf(ApiResponseModel $answer): array
    {
        return JsonNodeModel::of($answer->json())->listAt('posts')
            ?? throw new SocialNetworkUnreachableException('The Lemmy instance answered without posts.');
    }

    /**
     * @param list<ApiResponseModel> $answers
     *
     * @return list<JsonNodeModel> each post once, however many terms found it, newest first
     *
     * @throws SocialNetworkUnreachableException
     */
    private static function distinctPostViewsOf(array $answers): array
    {
        $postViewsById = [];
        foreach ($answers as $answer) {
            foreach (self::postViewsOf($answer) as $postView) {
                $postViewsById[$postView->node('post')->int('id') ?? 0] ??= $postView;
            }
        }
        krsort($postViewsById);

        return array_values($postViewsById);
    }

    /**
     * @param list<JsonNodeModel> $postViews
     *
     * @return list<ParsedEntryModel>
     */
    private function entriesOf(array $postViews, SocialAccount $account): array
    {
        $homeInstance = rtrim($account->getServiceUrl(), '/');
        $entries = [];
        foreach ($postViews as $postView) {
            $entry = $this->entryOf($postView, $homeInstance);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function entryOf(JsonNodeModel $postView, string $homeInstance): ?ParsedEntryModel
    {
        $post = $postView->node('post');
        $address = $post->string('ap_id');
        $postId = $post->int('id');
        if (null === $address || null === $postId || $post->isTrue('deleted') || $post->isTrue('removed')) {
            return null;
        }
        $thread = Discussion::platformThread(self::postPageOf($address, $homeInstance, $postId));


        return $this->postEntryOf($postView, $address)
            ->asDiscussionThread($thread)
            ->withDiscussionCounts(self::countsOf($postView->node('counts')))
            ->inCommunity(self::communityOf($postView->node('community')));
    }

    private static function communityOf(JsonNodeModel $community): ?PostCommunity
    {
        $identity = LemmyCommunity::identityOf($community);
        $address = $community->string('actor_id');

        return null === $identity || null === $address ? null : PostCommunity::of($identity, $address);
    }

    private function postEntryOf(JsonNodeModel $postView, string $address): ParsedEntryModel
    {
        $post = $postView->node('post');
        $creator = $postView->node('creator');
        $body = $this->bodyOf($post);

        return new ParsedEntryModel(
            guid: $address,
            url: null,
            headline: EntryTitle::of($post->string('name'), $body),
            author: $creator->string('display_name') ?? $creator->string('name'),
            summary: null,
            contentHtml: $body,
            publishedAt: DateParser::parse($post->string('published')),
            media: self::mediaOf($post),
            authorUrl: $creator->string('actor_id'),
            authorAvatarUrl: $creator->string('avatar'),
            statedLanguage: LemmyLanguageTag::of($post->int('language_id')),
        );
    }

    private function bodyOf(JsonNodeModel $post): ?string
    {
        $link = $post->string('url');
        $markdown = $post->string('body');
        $html = (null === $link ? '' : '<p>' . HtmlAnchor::to($link, $link) . '</p>')
            . (null === $markdown ? '' : $this->markdown->of($markdown));

        return '' === $html ? null : $html;
    }

    private static function postPageOf(string $address, string $homeInstance, int $postId): string
    {
        return null === LemmyPostUrl::parse($address) ? $homeInstance . '/post/' . $postId : $address;
    }

    private static function countsOf(JsonNodeModel $counts): DiscussionCounts
    {
        return DiscussionCounts::of($counts->int('score'), $counts->int('comments'));
    }

    private static function mediaOf(JsonNodeModel $post): ParsedEntryMediaModel
    {
        $thumbnail = $post->string('thumbnail_url');

        return new ParsedEntryMediaModel(null === $thumbnail ? null : new DeclaredImageModel($thumbnail));
    }

    /** @param list<JsonNodeModel> $postViews */
    private static function newestIdOf(array $postViews): ?string
    {
        $postIds = array_filter(array_map(
            static fn (JsonNodeModel $postView): ?int => $postView->node('post')->int('id'),
            $postViews,
        ), static fn (?int $postId): bool => null !== $postId);

        return [] === $postIds ? null : (string) max($postIds);
    }
}
