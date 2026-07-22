<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * Atom 1.0 (RFC 4287): the current dialect, namespaced
 * "http://www.w3.org/2005/Atom".
 */
final class Atom10Parser extends AbstractAtomParser
{
    public const NAMESPACE = 'http://www.w3.org/2005/Atom';

    protected function namespaceUri(): string
    {
        return self::NAMESPACE;
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
