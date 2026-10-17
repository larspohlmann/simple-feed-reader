<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreadPostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A reply in which the entry's author continued the post (#1668). */
#[ORM\Entity(repositoryClass: ThreadPostRepository::class)]
#[ORM\Table(name: 'thread_post')]
#[ORM\UniqueConstraint(name: 'uniq_thread_post_entry_position', columns: ['entry_id', 'position'])]
final class ThreadPost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $url;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt;

    #[ORM\Column(name: 'content_html', type: Types::TEXT)]
    private string $contentHtml;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $media;

    public function __construct(Entry $entry, int $position, ThreadPostContent $content)
    {
        $this->entry = $entry;
        $this->position = $position;
        $this->url = $content->url;
        $this->publishedAt = $content->publishedAt;
        $this->contentHtml = $content->html;
        $this->media = StoredList::orNull(EntryMedia::toJsonList($content->media));
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }
}
