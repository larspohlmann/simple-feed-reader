<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An entry's chapters, and the chapters file still to fetch for them when the feed only linked one. */
#[ORM\Embeddable]
final class EntryChapters
{
    public const int URL_MAX_LENGTH = 2048;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(name: 'chapters', type: Types::JSON, nullable: true)]
    private ?array $chapters = null;

    #[ORM\Column(name: 'chapters_url', type: Types::TEXT, nullable: true)]
    private ?string $url = null;

    /** @param list<EntryChapter> $chapters */
    public function declare(array $chapters, ?string $url): void
    {
        $this->chapters = self::stored($chapters);
        $this->url = $chapters === [] && $url !== null && \strlen($url) <= self::URL_MAX_LENGTH ? $url : null;
    }

    /** @param list<EntryChapter> $chapters */
    public function fill(array $chapters): void
    {
        $this->chapters = self::stored($chapters);
        $this->url = null;
    }

    /** @return list<EntryChapter> */
    public function getChapters(): array
    {
        return StoredList::read($this->chapters, EntryChapter::isComplete(...), EntryChapter::fromStored(...));
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @param list<EntryChapter> $chapters
     *
     * @return list<array{startSeconds: int, title: string}>|null
     */
    private static function stored(array $chapters): ?array
    {
        return StoredList::orNull(array_map(
            static fn (EntryChapter $chapter): array => $chapter->jsonSerialize(),
            $chapters,
        ));
    }
}
