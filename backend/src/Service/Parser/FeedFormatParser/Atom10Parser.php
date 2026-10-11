<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Model\FeedNamespace;

/** Atom 1.0 (RFC 4287): the current dialect. */
final readonly class Atom10Parser extends AbstractAtomParser
{
    protected function namespaceUri(): string
    {
        return FeedNamespace::Atom10->value;
    }

    protected function dateElements(): array
    {
        return ['published', 'updated'];
    }

    protected function descriptionElement(): string
    {
        return 'subtitle';
    }
}
