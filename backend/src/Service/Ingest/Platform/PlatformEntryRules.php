<?php

declare(strict_types=1);

namespace App\Service\Ingest\Platform;

use App\Service\Parser\ParsedEntry;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PlatformEntryRules
{
    /** @param iterable<PlatformEntryRule> $rules */
    public function __construct(
        #[AutowireIterator('app.platform_entry_rule')]
        private iterable $rules,
    ) {
    }

    public function apply(ParsedEntry $entry): ParsedEntry
    {
        foreach ($this->rules as $rule) {
            if ($rule->supports($entry)) {
                return $rule->apply($entry);
            }
        }

        return $entry;
    }
}
