<?php

declare(strict_types=1);

namespace App\Service\Chapter\Model;

final readonly class EmbedAddressModel
{
    public function __construct(public ChapteredEmbed $provider, public string $id)
    {
    }

    public function cacheKey(): string
    {
        return $this->provider->value . '.' . $this->id;
    }
}
