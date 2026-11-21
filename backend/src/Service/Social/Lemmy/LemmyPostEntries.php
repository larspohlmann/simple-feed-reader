<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\Discussion;
use App\Entity\DiscussionCounts;
use App\Entity\PostCommunity;
use App\Entity\SocialAccount;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Text\MarkdownHtml;
use App\Service\Foundation\Text\Support\HtmlAnchor;
use App\Service\Foundation\Url\Support\LemmyPostUrl;
use App\Service\Ingestion\Parser\Model\ParsedEntryMediaModel;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Ingestion\Parser\Support\DateParser;
use App\Service\Ingestion\Parser\Support\EntryTitle;
use App\Service\Outbound\Fetch\Model\ApiResponseModel;
use App\Service\Outbound\Image\Model\DeclaredImageModel;
use App\Service\Social\Completion\Model\KnownSharesModel;
use App\Service\Social\Lemmy\Support\LemmyCommunity;
use App\Service\Social\Lemmy\Support\LemmyLanguageTag;
use App\Service\Social\Lemmy\Support\LemmySearchedPost;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;
use App\Service\Social\Social\Support\SocialSearchMatch;

final readonly class LemmyPostEntries
{
    public function __construct(private MarkdownHtml $markdown)
    {
    }

    /** @throws SocialNetworkUnreachableException */
    public function page(SocialAccount $account, ApiResponseModel $answer): TimelinePageModel
    {
        $postViews = self::requirePostViews($answer);

        $entries = $this->buildEntries($postViews, $account);

        return TimelinePageModel::fromEntries($entries, KnownSharesModel::none(), self::pickNewestId($postViews));
    }

    /**
     * @param list<ApiResponseModel> $answers one per asked term
     *
     * @throws SocialNetworkUnreachableException
     */
    public function searchPage(SocialAccount $account, array $answers, string $query): TimelinePageModel
    {
        $postViews = self::collectDistinctPostViews($answers);
        $matches = SocialSearchMatch::matcher($query);
        $entries = array_values(array_filter(
            $this->buildEntries($postViews, $account),
            static fn (ParsedEntryModel $entry): bool => $matches(
                LemmySearchedPost::buildSearchedPost($entry->headline->text, $entry->contentHtml, $entry->authorUrl),
            ),
        ));

        return TimelinePageModel::fromEntries($entries, KnownSharesModel::none(), self::pickNewestId($postViews));
    }

    /**
     * @return list<JsonNodeModel>
     *
     * @throws SocialNetworkUnreachableException
     */
    private static function requirePostViews(ApiResponseModel $answer): array
    {
        return JsonNodeModel::fromValue($answer->json())->listAt('posts')
            ?? throw new SocialNetworkUnreachableException('The Lemmy instance answered without posts.');
    }

    /**
     * @param list<ApiResponseModel> $answers
     *
     * @return list<JsonNodeModel> each post once, however many terms found it, newest first
     *
     * @throws SocialNetworkUnreachableException
     */
    private static function collectDistinctPostViews(array $answers): array
    {
        $postViewsById = [];
        foreach ($answers as $answer) {
            foreach (self::requirePostViews($answer) as $postView) {
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
    private function buildEntries(array $postViews, SocialAccount $account): array
    {
        $homeInstance = rtrim($account->getServiceUrl(), '/');
        $entries = [];
        foreach ($postViews as $postView) {
            $entry = $this->parseEntry($postView, $homeInstance);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private function parseEntry(JsonNodeModel $postView, string $homeInstance): ?ParsedEntryModel
    {
        $post = $postView->node('post');
        $address = $post->string('ap_id');
        $postId = $post->int('id');
        if (null === $address || null === $postId || $post->isTrue('deleted') || $post->isTrue('removed')) {
            return null;
        }
        $thread = Discussion::platformThread(self::resolvePostPage($address, $homeInstance, $postId));


        return $this->buildPostEntry($postView, $address)
            ->asDiscussionThread($thread)
            ->withDiscussionCounts(self::buildCounts($postView->node('counts')))
            ->withLinkedUrl($postView->node('post')->string('url'))
            ->inCommunity(self::parseCommunity($postView->node('community')));
    }

    private static function parseCommunity(JsonNodeModel $community): ?PostCommunity
    {
        $identity = LemmyCommunity::parseIdentity($community);
        $address = $community->string('actor_id');

        return null === $identity || null === $address ? null : PostCommunity::fromIdentityAndUrl($identity, $address);
    }

    private function buildPostEntry(JsonNodeModel $postView, string $address): ParsedEntryModel
    {
        $post = $postView->node('post');
        $creator = $postView->node('creator');
        $body = $this->buildBody($post);

        return new ParsedEntryModel(
            guid: $address,
            url: null,
            headline: EntryTitle::buildHeadline($post->string('name'), $body),
            author: $creator->string('display_name') ?? $creator->string('name'),
            summary: null,
            contentHtml: $body,
            publishedAt: DateParser::parse($post->string('published')),
            media: self::buildMedia($post),
            authorUrl: $creator->string('actor_id'),
            authorAvatarUrl: $creator->string('avatar'),
            statedLanguage: LemmyLanguageTag::fromLanguageId($post->int('language_id')),
        );
    }

    private function buildBody(JsonNodeModel $post): ?string
    {
        $link = $post->string('url');
        $markdown = $post->string('body');
        $html = (null === $link ? '' : '<p>' . HtmlAnchor::to($link, $link) . '</p>')
            . (null === $markdown ? '' : $this->markdown->toHtml($markdown));

        return '' === $html ? null : $html;
    }

    private static function resolvePostPage(string $address, string $homeInstance, int $postId): string
    {
        return null === LemmyPostUrl::parse($address) ? $homeInstance . '/post/' . $postId : $address;
    }

    private static function buildCounts(JsonNodeModel $counts): DiscussionCounts
    {
        return DiscussionCounts::fromScoreAndComments($counts->int('score'), $counts->int('comments'));
    }

    private static function buildMedia(JsonNodeModel $post): ParsedEntryMediaModel
    {
        $thumbnail = $post->string('thumbnail_url');

        return new ParsedEntryMediaModel(null === $thumbnail ? null : new DeclaredImageModel($thumbnail));
    }

    /** @param list<JsonNodeModel> $postViews */
    private static function pickNewestId(array $postViews): ?string
    {
        $postIds = array_filter(array_map(
            static fn (JsonNodeModel $postView): ?int => $postView->node('post')->int('id'),
            $postViews,
        ), static fn (?int $postId): bool => null !== $postId);

        return [] === $postIds ? null : (string) max($postIds);
    }
}
