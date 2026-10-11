<?php

declare(strict_types=1);

namespace App\Service\Parser\FeedFormatParser;

use App\Service\Parser\Model\FeedNamespace;

/**
 * Atom 0.3: the pre-standard dialect.
 * Still served by major publishers (e.g. tagesschau's primary feed). It dates
 * entries with <issued>/<modified> and names the feed description <tagline>.
 */
final readonly class Atom03Parser extends AbstractAtomParser
{
    protected function namespaceUri(): string
    {
        return FeedNamespace::Atom03->value;
    }

    protected function dateElements(): array
    {
        return ['issued', 'modified'];
    }

    protected function descriptionElement(): string
    {
        return 'tagline';
    }
}
