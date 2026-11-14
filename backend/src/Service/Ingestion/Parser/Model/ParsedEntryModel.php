<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Parser\Model;

use App\Entity\Discussion;
use App\Entity\Headline;
use App\Service\Outbound\Image\Model\DeclaredImageModel;

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
        return clone($this, ['url' => null, 'discussion' => $thread]);
    }

    #[\NoDiscard]
    public function withPostText(Headline $headline, ?string $summary, ?string $contentHtml): self
    {
        return clone($this, ['headline' => $headline, 'summary' => $summary, 'contentHtml' => $contentHtml]);
    }

    #[\NoDiscard]
    public function withAuthor(string $author, string $authorUrl): self
    {
        return clone($this, ['author' => $author, 'authorUrl' => $authorUrl]);
    }

    #[\NoDiscard]
    public function withContentHtml(?string $contentHtml): self
    {
        return $this->withPostText($this->headline, $this->summary, $contentHtml);
    }

    #[\NoDiscard]
    private function withMedia(ParsedEntryMediaModel $media): self
    {
        return $media === $this->media ? $this : clone($this, ['media' => $media]);
    }
}
