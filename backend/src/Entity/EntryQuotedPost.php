<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The post an entry quotes, stamped and read together; no url is the "quotes nothing" case.
 * Urls are TEXT: the entry table has no MySQL row width left for more VARCHAR(2048); StorableQuotedPost bounds them.
 */
#[ORM\Embeddable]
final class EntryQuotedPost
{
    #[ORM\Column(name: 'quoted_url', type: Types::TEXT, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(name: 'quoted_author_name', length: QuotedPostContent::NAME_MAX, nullable: true)]
    private ?string $authorName = null;

    #[ORM\Column(name: 'quoted_author_handle', length: QuotedPostContent::NAME_MAX, nullable: true)]
    private ?string $authorHandle = null;

    #[ORM\Column(name: 'quoted_author_avatar_url', type: Types::TEXT, nullable: true)]
    private ?string $authorAvatarUrl = null;

    #[ORM\Column(name: 'quoted_posted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    #[ORM\Column(name: 'quoted_text', type: Types::TEXT, nullable: true)]
    private ?string $text = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(name: 'quoted_media', type: Types::JSON, nullable: true)]
    private ?array $media = null;

    #[ORM\Column(name: 'quoted_nested_url', type: Types::TEXT, nullable: true)]
    private ?string $nestedUrl = null;

    #[ORM\Column(name: 'quoted_nested_handle', length: QuotedPostContent::NAME_MAX, nullable: true)]
    private ?string $nestedHandle = null;

    public function store(QuotedPostContent $quote): void
    {
        $this->url = $quote->url;
        $this->authorName = $quote->authorName;
        $this->authorHandle = $quote->authorHandle;
        $this->authorAvatarUrl = $quote->authorAvatarUrl;
        $this->postedAt = $quote->postedAt;
        $this->text = $quote->text;
        $this->media = StoredList::orNull(EntryMedia::toJsonList($quote->media));
        $this->nestedUrl = $quote->nestedQuote?->url;
        $this->nestedHandle = $quote->nestedQuote?->handle;
    }

    public function clear(): void
    {
        $this->url = null;
        $this->authorName = null;
        $this->authorHandle = null;
        $this->authorAvatarUrl = null;
        $this->postedAt = null;
        $this->text = null;
        $this->media = null;
        $this->nestedUrl = null;
        $this->nestedHandle = null;
    }

    public function read(): ?QuotedPostContent
    {
        if ($this->url === null || $this->authorName === null || $this->authorHandle === null) {
            return null;
        }

        return new QuotedPostContent(
            $this->url,
            $this->authorName,
            $this->authorHandle,
            $this->authorAvatarUrl,
            $this->postedAt,
            $this->text ?? '',
            StoredList::read($this->media, EntryMedium::isComplete(...), EntryMedium::fromStored(...)),
            $this->nestedUrl === null ? null : new QuotedPostReference($this->nestedUrl, $this->nestedHandle),
        );
    }
}
