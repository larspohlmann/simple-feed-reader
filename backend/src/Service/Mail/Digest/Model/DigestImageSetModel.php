<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Model;

final readonly class DigestImageSetModel
{
    /**
     * @param list<EmbeddedImageModel> $images
     * @param array<string, string>    $cidByUrl
     */
    public function __construct(
        public array $images,
        private array $cidByUrl,
    ) {
    }

    public function cidFor(?string $url): ?string
    {
        return $url === null ? null : ($this->cidByUrl[$url] ?? null);
    }
}
