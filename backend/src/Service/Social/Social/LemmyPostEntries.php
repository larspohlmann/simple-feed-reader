<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Discussion;
use App\Entity\DiscussionCounts;
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
use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Completion\Model\KnownSharesModel;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\TimelinePageModel;

/** A Lemmy post listing as entries in the shape of the instance's RSS posts: each a thread to vote and comment on. */
final readonly class LemmyPostEntries
{
    public function __construct(private MarkdownHtml $markdown)
    {
    }

    /** @throws SocialNetworkUnreachableException */
    public function page(SocialAccount $account, ApiResponseModel $answer): TimelinePageModel
    {
        $postViews = JsonNodeModel::of($answer->json())->listAt('posts')
            ?? throw new SocialNetworkUnreachableException('The Lemmy instance answered without posts.');
        $homeInstance = rtrim($account->getServiceUrl(), '/');
        $entries = [];
        foreach ($postViews as $postView) {
            $entry = $this->entryOf($postView, $homeInstance);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return TimelinePageModel::of($entries, KnownSharesModel::none(), self::newestIdOf($postViews));
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
            ->withDiscussionCounts(self::countsOf($postView->node('counts')));
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
