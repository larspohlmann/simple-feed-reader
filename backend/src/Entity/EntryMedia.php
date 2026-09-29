<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's feed-declared media, stamped and read together: visual media to show and enclosures to play or
 * download. An empty list persists as null, the "no media" case.
 */
#[ORM\Embeddable]
final class EntryMedia
{
    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(name: 'media', type: Types::JSON, nullable: true)]
    private ?array $media = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(name: 'attachments', type: Types::JSON, nullable: true)]
    private ?array $attachments = null;

    /**
     * @param list<EntryMedium>     $media
     * @param list<EntryAttachment> $attachments
     */
    public function set(array $media, array $attachments): void
    {
        $this->media = self::encode($media);
        $this->attachments = self::encode($attachments);
    }

    /** Removes every medium whose url matches, re-indexed; attachments are untouched. */
    public function removeUrl(string $url): void
    {
        if ($this->media === null) {
            return;
        }

        $remaining = array_values(array_filter(
            $this->media,
            static fn (array $medium): bool => ($medium['url'] ?? null) !== $url,
        ));
        $this->media = $remaining === [] ? null : $remaining;
    }

    /** @return list<EntryMedium> */
    public function getMedia(): array
    {
        $complete = array_filter($this->media ?? [], EntryMedium::isComplete(...));

        return array_values(array_map(EntryMedium::fromStored(...), $complete));
    }

    /** @return list<EntryAttachment> */
    public function getAttachments(): array
    {
        $complete = array_filter($this->attachments ?? [], EntryAttachment::isComplete(...));

        return array_values(array_map(EntryAttachment::fromStored(...), $complete));
    }

    /**
     * Maps a media value-object list to the JSON arrays stored, exported, and
     * served — the one place that turns EntryMedium/EntryAttachment into arrays,
     * shared by the entity, the API mapper, and the backup exporter.
     *
     * @param list<EntryMedium>|list<EntryAttachment> $items
     *
     * @return list<array<string, string|int>>
     */
    public static function toJsonList(array $items): array
    {
        return array_map(static fn (EntryMedium|EntryAttachment $item): array => $item->jsonSerialize(), $items);
    }

    /**
     * @param list<EntryMedium>|list<EntryAttachment> $items
     *
     * @return list<array<string, string|int>>|null
     */
    private static function encode(array $items): ?array
    {
        return $items === [] ? null : self::toJsonList($items);
    }
}
