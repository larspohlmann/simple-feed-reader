<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

use App\Service\Image\Model\DeclaredImageModel;

final readonly class ParsedEntryMediaModel
{
    public function __construct(
        public ?DeclaredImageModel $image = null,
        public ?ParsedMediaBundleModel $mediaBundle = null,
    ) {
    }
}
