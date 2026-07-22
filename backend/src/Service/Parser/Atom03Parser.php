<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Atom 0.3: the pre-standard dialect, namespaced "http://purl.org/atom/ns#".
 * Still served by major publishers (e.g. tagesschau's primary feed). It dates
 * entries with <issued>/<modified> and names the feed description <tagline>.
 */
final class Atom03Parser extends AbstractAtomParser
{
    public const NAMESPACE = 'http://purl.org/atom/ns#';

    protected function namespaceUri(): string
    {
        return self::NAMESPACE;
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
