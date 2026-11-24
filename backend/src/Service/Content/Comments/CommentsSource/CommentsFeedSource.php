<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Content\Comments\CommentBodyRenderer;
use App\Service\Content\Comments\CommentsEndpointFetcher;
use App\Service\Content\Comments\Exception\CommentsUnavailableException;
use App\Service\Content\Comments\Model\CommentAuthorModel;
use App\Service\Content\Comments\Model\CommentBodyModel;
use App\Service\Content\Comments\Model\CommentsAnswerModel;
use App\Service\Content\Comments\Model\CommentsAudienceModel;
use App\Service\Content\Comments\Model\CommentsEndpointModel;
use App\Service\Content\Comments\Model\CommentsPageModel;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Content\Comments\Pass\CommentsRequest;
use App\Service\Ingestion\Parser\Exception\FeedParseException;
use App\Service\Ingestion\Parser\FeedParser;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

final readonly class CommentsFeedSource implements CommentsSourceInterface
{
    public function __construct(
        private CommentsEndpointFetcher $endpointFetcher,
        private FeedParser $parser,
        private CommentBodyRenderer $bodies,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== $entry->getDiscussion()->commentsFeedUrl;
    }

    public function buildAudience(User $user, Entry $entry): CommentsAudienceModel
    {
        return new CommentsAudienceModel($entry->getDiscussion()->commentsFeedUrl ?? '');
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $feedUrl = self::requireFeedUrl($request->entry);
        $endpoint = new CommentsEndpointModel(
            $feedUrl,
            'The comments feed host asked us to wait.',
            'The comments feed could not be fetched.',
        );

        return CommentsAnswerModel::settled($this->endpointFetcher->body($feedUrl, $endpoint, $request->user));
    }

    public function read(CommentsRequest $request, string $answer): CommentsPageModel
    {
        try {
            $parsed = $this->parser->parse($answer)->entries;
        } catch (FeedParseException $exception) {
            throw new CommentsUnavailableException('The comments feed could not be parsed.', previous: $exception);
        }

        return new CommentsPageModel($this->comments($request->entry, $parsed));
    }

    /**
     * @param list<ParsedEntryModel> $parsed
     *
     * @return list<EntryCommentModel>
     */
    private function comments(Entry $entry, array $parsed): array
    {
        $comments = [];
        foreach ($parsed as $item) {
            if ($item->guid === $entry->getGuid()) {
                continue;
            }
            $comments[] = new EntryCommentModel(
                new CommentAuthorModel($item->author, null, $item->authorUrl, $item->authorAvatarUrl),
                $item->url,
                $item->publishedAt,
                (string) $this->bodies->buildHtml(CommentBodyModel::fromHtml($item->contentHtml ?? '')),
                $item->author !== null && $item->author === $entry->getAuthor(),
            );
        }

        return $comments;
    }

    private static function requireFeedUrl(Entry $entry): string
    {
        return $entry->getDiscussion()->commentsFeedUrl
            ?? throw new CommentsUnavailableException('The entry has no comments feed.');
    }
}
