<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Model\FeedNamespace;

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
