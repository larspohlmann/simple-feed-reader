<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

final class FullReplacePayload
{
    /** Without it the serializer gives a missing nullable setting null instead of refusing the body. */
    public const array CONTEXT = [AbstractNormalizer::REQUIRE_ALL_PROPERTIES => true];
}
