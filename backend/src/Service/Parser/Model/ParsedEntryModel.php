<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Entity\Discussion;
use App\Service\Image\Model\DeclaredImageModel;

/** @SuppressWarnings("PHPMD.ExcessiveParameterList") a pure data carrier: one parameter per parsed field. */
final readonly class ParsedEntryModel
{
    public Discussion $discussion;

    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
        public ParsedEntryMediaModel $media = new ParsedEntryMediaModel(),
        /** @var list<ParsedCategoryModel> */
        public array $categories = [],
        ?Discussion $discussion = null,
        public ?string $authorUrl = null,
        public bool $titleDerived = false,
    ) {
        $this->discussion = $discussion ?? Discussion::none();
    }

    public function withShowArtwork(?DeclaredImageModel $showArtwork): self
    {
        return $this->withMedia($this->media->withShowArtwork($showArtwork));
    }

    public function asDiscussionThread(Discussion $thread): self
    {
        return new self(
            guid: $this->guid,
            url: null,
            title: $this->title,
            author: $this->author,
            summary: $this->summary,
            contentHtml: $this->contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $thread,
            authorUrl: $this->authorUrl,
            titleDerived: $this->titleDerived,
        );
    }

    public function parsedTitle(): ParsedTitleModel
    {
        return $this->titleDerived ? ParsedTitleModel::derived($this->title) : ParsedTitleModel::fromFeed($this->title);
    }

    public function withPostText(ParsedTitleModel $title, ?string $summary, ?string $contentHtml): self
    {
        return new self(
            guid: $this->guid,
            url: $this->url,
            title: $title->text,
            author: $this->author,
            summary: $summary,
            contentHtml: $contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $this->discussion,
            authorUrl: $this->authorUrl,
            titleDerived: $title->derived,
        );
    }

    public function withContentHtml(?string $contentHtml): self
    {
        return $this->withPostText($this->parsedTitle(), $this->summary, $contentHtml);
    }

    private function withMedia(ParsedEntryMediaModel $media): self
    {
        return $media === $this->media ? $this : new self(
            guid: $this->guid,
            url: $this->url,
            title: $this->title,
            author: $this->author,
            summary: $this->summary,
            contentHtml: $this->contentHtml,
            publishedAt: $this->publishedAt,
            media: $media,
            categories: $this->categories,
            discussion: $this->discussion,
            authorUrl: $this->authorUrl,
            titleDerived: $this->titleDerived,
        );
    }
}
