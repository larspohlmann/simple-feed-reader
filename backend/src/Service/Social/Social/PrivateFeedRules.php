<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\PrivateFeedRule\PrivateFeedRuleInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The rules a network's private feeds follow. */
final readonly class PrivateFeedRules
{
    public function __construct(
        #[AutowireLocator('app.social_private_feed_rule')]
        private ContainerInterface $rulesByNetwork,
    ) {
    }

    public function selectRule(SocialNetwork $network): PrivateFeedRuleInterface
    {
        $rule = $this->rulesByNetwork->get($network->value);
        \assert($rule instanceof PrivateFeedRuleInterface);

        return $rule;
    }
}
