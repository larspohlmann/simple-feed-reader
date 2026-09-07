<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An entry's feed-declared media, split into the two lists it keeps: visual
 * media to show and enclosures to play or download.
 *
 * Embedded into Entry as one field rather than two of its own columns — the same
 * move EntryImage makes, and for the same reason: PHPMD's field-count ceiling on
 * Entry is a proxy for a real seam, and these two lists are stamped and read
 * together. Each list is a nullable JSON column; an empty list persists as null,
 * the "no media" case the reader treats as first-class, exactly like a null
 * image. The array<->object mapping lives here so Entry keeps only thin
 * accessors and no serialization logic.
 */
#[ORM\Embeddable]
class EntryMedia
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

    /** @return list<EntryMedium> */
    public function getMedia(): array
    {
        return array_map(EntryMedium::fromArray(...), $this->media ?? []);
    }

    /** @return list<EntryAttachment> */
    public function getAttachments(): array
    {
        return array_map(EntryAttachment::fromArray(...), $this->attachments ?? []);
    }

    /**
     * @param list<\JsonSerializable> $items
     *
     * @return list<array<string, mixed>>|null
     */
    private static function encode(array $items): ?array
    {
        return $items === [] ? null : array_map(static fn (\JsonSerializable $item): array => $item->jsonSerialize(), $items);
    }
}
