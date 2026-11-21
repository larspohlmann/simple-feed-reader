<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest;

use App\Entity\CanonicalItem;
use App\Service\Ingestion\Ingest\CrossPostKeyRule\CrossPostKeyRuleInterface;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;

final readonly class CrossPostKeys
{
    /** @param iterable<CrossPostKeyRuleInterface> $rules */
    public function __construct(
        private iterable $rules,
    ) {
    }

    public function findHash(CanonicalItem $item, ParsedEntryModel $parsed): ?string
    {
        foreach ($this->rules as $rule) {
            $hash = $rule->findHash($item, $parsed);
            if (null !== $hash) {
                return $hash;
            }
        }

        return null;
    }
}
