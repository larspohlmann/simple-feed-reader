<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;
use App\Service\Content\Reader\ReaderUrlSignature;

/**
 * @phpstan-type LinkCardFields array{
 *   url: string, title: string, description: string|null, imageUrl: string|null, siteName: string
 * }
 * @phpstan-type LinkCardRow array{
 *   url: string, title: string, description: string|null, imageUrl: string|null, siteName: string,
 *   readerToken: string
 * }
 */
final readonly class LinkCardJson
{
    public function __construct(private ReaderUrlSignature $signature)
    {
    }

    /** @return LinkCardRow */
    public function from(LinkCardContent $card): array
    {
        return [...self::toFields($card), 'readerToken' => $this->signature->tokenFor($card->url)];
    }

    /** @return LinkCardFields */
    public static function toFields(LinkCardContent $card): array
    {
        return $card->toStored();
    }

    /** @return LinkCardRow|null */
    public function fromOptional(?LinkCardContent $card): ?array
    {
        return $card === null ? null : $this->from($card);
    }
}
