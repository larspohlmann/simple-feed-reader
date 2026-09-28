<?php

declare(strict_types=1);

namespace App\Service\Ingest;

use App\Service\Ingest\PlatformEntryRule\PlatformEntryRuleInterface;
use App\Service\Parser\Model\ParsedEntryModel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class PlatformEntryRules
{
    /** @param iterable<PlatformEntryRuleInterface> $rules */
    public function __construct(
        #[AutowireIterator('app.platform_entry_rule')]
        private iterable $rules,
    ) {
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        foreach ($this->rules as $rule) {
            if ($rule->supports($entry)) {
                return $rule->apply($entry);
            }
        }

        return $entry;
    }
}
