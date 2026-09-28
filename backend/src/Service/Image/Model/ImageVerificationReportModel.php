<?php

declare(strict_types=1);

namespace App\Service\Image\Model;

final readonly class ImageVerificationReportModel
{
    public function __construct(
        public int $measured,
        public int $kept,
        public int $dropped,
        public int $retried,
    ) {
    }
}
