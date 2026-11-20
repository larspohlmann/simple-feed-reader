<?php

declare(strict_types=1);

namespace App\Service\Foundation\CanonicalItem\Model;

use App\Entity\CanonicalItem;
use App\Enum\CanonicalItemKind;

final readonly class CanonicalKeyModel
{
    public function __construct(
        public CanonicalItemKind $kind,
        public string $key,
    ) {
    }

    public function keyHash(): string
    {
        return CanonicalItem::hashKey($this->key);
    }
}
