<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Entity\Discussion;
use App\Entity\Headline;
use App\Service\Image\Model\DeclaredImageModel;

/** @SuppressWarnings("PHPMD.ExcessiveParameterList") a pure data carrier: one parameter per parsed field. */
final readonly class ParsedEntryModel
{
    public Discussion $discussion;

    public function __construct(
        public string $guid,
        public ?string $url,
        public Headline $headline,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
        public ParsedEntryMediaModel $media = new ParsedEntryMediaModel(),
        /** @var list<ParsedCategoryModel> */
        public array $categories = [],
        ?Discussion $discussion = null,
        public ?string $authorUrl = null,
        public ?string $authorAvatarUrl = null,
    ) {
        $this->discussion = $discussion ?? Discussion::none();
    }

    #[\NoDiscard]
    public function withShowArtwork(?DeclaredImageModel $showArtwork): self
    {
        return $this->withMedia($this->media->withShowArtwork($showArtwork));
    }

    #[\NoDiscard]
    public function asDiscussionThread(Discussion $thread): self
    {
        return new self(
            guid: $this->guid,
            url: null,
            headline: $this->headline,
            author: $this->author,
            summary: $this->summary,
            contentHtml: $this->contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $thread,
            authorUrl: $this->authorUrl,
            authorAvatarUrl: $this->authorAvatarUrl,
        );
    }

    #[\NoDiscard]
    public function withPostText(Headline $headline, ?string $summary, ?string $contentHtml): self
    {
        return new self(
            guid: $this->guid,
            url: $this->url,
            headline: $headline,
            author: $this->author,
            summary: $summary,
            contentHtml: $contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $this->discussion,
            authorUrl: $this->authorUrl,
            authorAvatarUrl: $this->authorAvatarUrl,
        );
    }

    #[\NoDiscard]
    public function withAuthor(string $author, string $authorUrl): self
    {
        return new self(
            guid: $this->guid,
            url: $this->url,
            headline: $this->headline,
            author: $author,
            summary: $this->summary,
            contentHtml: $this->contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $this->discussion,
            authorUrl: $authorUrl,
            authorAvatarUrl: $this->authorAvatarUrl,
        );
    }

    #[\NoDiscard]
    public function withContentHtml(?string $contentHtml): self
    {
        return $this->withPostText($this->headline, $this->summary, $contentHtml);
    }

    #[\NoDiscard]
    private function withMedia(ParsedEntryMediaModel $media): self
    {
        return $media === $this->media ? $this : new self(
            guid: $this->guid,
            url: $this->url,
            headline: $this->headline,
            author: $this->author,
            summary: $this->summary,
            contentHtml: $this->contentHtml,
            publishedAt: $this->publishedAt,
            media: $media,
            categories: $this->categories,
            discussion: $this->discussion,
            authorUrl: $this->authorUrl,
            authorAvatarUrl: $this->authorAvatarUrl,
        );
    }
}
