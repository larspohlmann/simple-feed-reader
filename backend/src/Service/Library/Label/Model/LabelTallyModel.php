<?php

declare(strict_types=1);

namespace App\Service\Library\Label\Model;

final readonly class LabelTallyModel
{
    public function __construct(
        public int $id,
        public string $name,
        public int $favoriteCount,
        public int $keptCount,
        public int $articleCount,
    ) {
    }
}
