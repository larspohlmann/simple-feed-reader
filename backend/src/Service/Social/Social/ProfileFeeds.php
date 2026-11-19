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
        return array_find(
            SocialNetwork::cases(),
            fn (SocialNetwork $network): bool => null !== $this->selectRule($network)->parseIdentity($feedUrl),
        );
    }

    public function findRule(string $feedUrl): ?ProfileFeedRuleInterface
    {
        $network = $this->findNetwork($feedUrl);

        return null === $network ? null : $this->selectRule($network);
    }
}
