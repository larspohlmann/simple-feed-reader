<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class PartialPatchPayload
{
    /** An unknown key is refused instead of ignored. */
    public const array CONTEXT = [
        AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false,
        DenormalizerInterface::COLLECT_EXTRA_ATTRIBUTES_ERRORS => true,
    ];
}
