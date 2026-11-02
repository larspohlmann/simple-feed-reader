<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;
use App\Service\Reader\ReaderUrlSignature;

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
    public function of(LinkCardContent $card): array
    {
        return [...self::fieldsOf($card), 'readerToken' => $this->signature->tokenFor($card->url)];
    }

    /** @return LinkCardFields */
    public static function fieldsOf(LinkCardContent $card): array
    {
        return $card->toStored();
    }

    /** @return LinkCardRow|null */
    public function ofOrNull(?LinkCardContent $card): ?array
    {
        return $card === null ? null : $this->of($card);
    }
}
