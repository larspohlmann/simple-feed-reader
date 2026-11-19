<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\ImportRule\ImportRuleInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The rules a network's follows come in by on an import. */
final readonly class ImportRules
{
    public function __construct(
        #[AutowireLocator('app.social_import_rule')]
        private ContainerInterface $rulesByNetwork,
    ) {
    }

    public function selectRule(SocialNetwork $network): ImportRuleInterface
    {
        $rule = $this->rulesByNetwork->get($network->value);
        \assert($rule instanceof ImportRuleInterface);

        return $rule;
    }
}
