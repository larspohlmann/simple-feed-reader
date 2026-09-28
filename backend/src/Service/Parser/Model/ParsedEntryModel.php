<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Entity\Discussion;

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
    ) {
        $this->discussion = $discussion ?? Discussion::none();
    }

    public function withPlatformRewrite(?string $url, ?string $contentHtml, Discussion $discussion): self
    {
        return new self(
            guid: $this->guid,
            url: $url,
            title: $this->title,
            author: $this->author,
            summary: $this->summary,
            contentHtml: $contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $discussion,
            authorUrl: $this->authorUrl,
        );
    }
}
