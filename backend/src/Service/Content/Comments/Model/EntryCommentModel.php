<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Model;

final readonly class EntryCommentModel
{
    public function __construct(
        public CommentAuthorModel $author,
        public ?string $url,
        public ?\DateTimeImmutable $publishedAt,
        public string $html,
        public bool $byEntryAuthor,
        public CommentEmbedsModel $embeds = new CommentEmbedsModel(),
        public int $depth = 0,
        public ?CommentPostModel $post = null,
        public ?int $score = null,
        public ?CommentVoteModel $vote = null,
    ) {
    }

    #[\NoDiscard]
    public function withPost(CommentPostModel $post): self
    {
        return clone($this, ['post' => $post]);
    }

    #[\NoDiscard]
    public function atDepth(int $depth): self
    {
        return clone($this, ['depth' => $depth]);
    }

    #[\NoDiscard]
    public function withVote(CommentVoteModel $vote): self
    {
        return clone($this, ['vote' => $vote]);
    }
}
