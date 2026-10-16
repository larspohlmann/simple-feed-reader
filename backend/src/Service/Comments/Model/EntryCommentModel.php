<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

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
    ) {
    }

    public function atDepth(int $depth): self
    {
        return new self(
            $this->author,
            $this->url,
            $this->publishedAt,
            $this->html,
            $this->byEntryAuthor,
            $this->embeds,
            $depth,
        );
    }
}
