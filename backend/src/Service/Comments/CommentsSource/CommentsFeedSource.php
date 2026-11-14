<?php

declare(strict_types=1);

namespace App\Service\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\CommentsEndpointFetcher;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsAudienceModel;
use App\Service\Comments\Model\CommentsEndpointModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Sanitize\EntrySanitizer;

final readonly class CommentsFeedSource implements CommentsSourceInterface
{
    public function __construct(
        private CommentsEndpointFetcher $endpoints,
        private FeedParser $parser,
        private EntrySanitizer $sanitizer,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== $entry->getDiscussion()->commentsFeedUrl;
    }

    public function audienceOf(User $user, Entry $entry): CommentsAudienceModel
    {
        return new CommentsAudienceModel($entry->getDiscussion()->commentsFeedUrl ?? '');
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $feedUrl = self::feedUrlOf($request->entry);
        $endpoint = new CommentsEndpointModel(
            $feedUrl,
            $feedUrl,
            'The comments feed host asked us to wait.',
            'The comments feed could not be fetched.',
        );

        return CommentsAnswerModel::settled($this->endpoints->body($endpoint, $request->user));
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
                (string) $this->sanitizer->sanitize($item->contentHtml),
                $item->author !== null && $item->author === $entry->getAuthor(),
            );
        }

        return $comments;
    }

    private static function feedUrlOf(Entry $entry): string
    {
        return $entry->getDiscussion()->commentsFeedUrl
            ?? throw new CommentsUnavailableException('The entry has no comments feed.');
    }
}
