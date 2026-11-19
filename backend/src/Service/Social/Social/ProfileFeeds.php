<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\ProfileFeedRule\ProfileFeedRuleInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Which network's profile feed a URL is, and the rule that reads it. */
final readonly class ProfileFeeds
{
    public function __construct(
        #[AutowireLocator('app.social_profile_feed_rule')]
        private ContainerInterface $rulesByNetwork,
    ) {
    }

    public function selectRule(SocialNetwork $network): ProfileFeedRuleInterface
    {
        $rule = $this->rulesByNetwork->get($network->value);
        \assert($rule instanceof ProfileFeedRuleInterface);

        return $rule;
    }

    public function findNetwork(string $feedUrl): ?SocialNetwork
    {
        foreach (SocialNetwork::cases() as $network) {
            if (null !== $this->selectRule($network)->parseIdentity($feedUrl)) {
                return $network;
            }
        }

        return null;
    }

    public function findRule(string $feedUrl): ?ProfileFeedRuleInterface
    {
        foreach (SocialNetwork::cases() as $network) {
            $rule = $this->selectRule($network);
            if (null !== $rule->parseIdentity($feedUrl)) {
                return $rule;
            }
        }

        return null;
    }
}
