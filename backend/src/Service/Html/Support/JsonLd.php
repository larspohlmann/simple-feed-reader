<?php

declare(strict_types=1);

namespace App\Service\Html\Support;

use Dom\Element;
use Dom\HTMLDocument;

final class JsonLd
{
    private const string SCRIPT_SELECTOR = 'script[type="application/ld+json"]';

    /** @return iterable<Element> */
    public static function scriptsIn(HTMLDocument $document): iterable
    {
        yield from $document->querySelectorAll(self::SCRIPT_SELECTOR);
    }

    public static function isScript(Element $script): bool
    {
        return $script->matches(self::SCRIPT_SELECTOR);
    }

    /** @return array<mixed> */
    public static function decode(Element $script): array
    {
        $decoded = json_decode((string) $script->textContent, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $block
     *
     * @return iterable<array<mixed>>
     */
    public static function nodesIn(array $block): iterable
    {
        yield $block;
        foreach ($block as $child) {
            if (\is_array($child)) {
                yield from self::nodesIn($child);
            }
        }
    }

    private function __construct()
    {
    }
}
