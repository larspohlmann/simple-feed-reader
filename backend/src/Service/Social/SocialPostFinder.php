<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostResolver\PostResolverInterface;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The post an entry is, found on the account that acts on it: where every post interaction starts. */
final readonly class SocialPostFinder
{
    public function __construct(
        private ActingAccountChooser $accounts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_resolver', defaultIndexMethod: 'network')]
        private ContainerInterface $resolvers,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function find(User $user, Entry $entry): SocialPostModel
    {
        $address = SocialPostAddress::of($entry)
            ?? throw new NotASocialPostException('This entry is not a Bluesky or Mastodon post.');
        $account = $this->accounts->accountFor($user, $entry, $address->network);
        $resolver = $this->resolverOn($address->network);

        return $this->guard->actAs(
            $account,
            static fn (): SocialPostModel => $resolver->resolve($account, $address->address),
        );
    }

    private function resolverOn(SocialNetwork $network): PostResolverInterface
    {
        $resolver = $this->resolvers->get($network->value);
        \assert($resolver instanceof PostResolverInterface);

        return $resolver;
    }
}
