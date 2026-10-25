<?php

declare(strict_types=1);

namespace App\Service\Chapter\Pass;

use Dom\Element;
use Dom\Node;

/** One `<br>`-separated line of a description paragraph. */
final readonly class DescriptionLine
{
    /** @param list<Node> $nodes */
    public function __construct(public Element $block, private array $nodes, private ?Element $breakAfter)
    {
    }

    public function text(): string
    {
        return implode('', array_map(static fn (Node $node): ?string => $node->textContent, $this->nodes));
    }

    public function remove(): void
    {
        foreach ($this->nodes as $node) {
            $this->block->removeChild($node);
        }
        $this->breakAfter?->remove();
    }
}
