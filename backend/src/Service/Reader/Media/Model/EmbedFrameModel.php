<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

final readonly class EmbedFrameModel
{
    public function __construct(
        public string $pattern,
        public EmbedKind $kind,
        public EmbedShape $shape,
    ) {
    }
}
