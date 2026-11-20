<?php

declare(strict_types=1);

namespace App\Service\Foundation\CanonicalItem;

use App\Entity\Entry;
use App\Service\Foundation\CanonicalItem\CanonicalKeyRule\CanonicalKeyRuleInterface;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;

final readonly class CanonicalKeys
{
    /** @param iterable<CanonicalKeyRuleInterface> $rules */
    public function __construct(
        private iterable $rules,
    ) {
    }

    public function findKey(Entry $entry): ?CanonicalKeyModel
    {
        foreach ($this->rules as $rule) {
            $key = $rule->findKey($entry);
            if (null !== $key) {
                return $key;
            }
        }

        return null;
    }
}
