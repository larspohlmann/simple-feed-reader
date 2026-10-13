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
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialCountsModel;
use App\Service\Social\PostCountsSource\PostCountsSourceInterface;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** A post's live counts: from the cache, else its network's public source, else the user's acting account. */
final readonly class SocialCounts
{
    public function __construct(
        private SocialCountsCache $cache,
        private SocialPostFinder $posts,
        #[AutowireLocator('app.social_post_counts_source', defaultIndexMethod: 'network')]
        private ContainerInterface $publicSources,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws SocialPostNotFoundException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException
     */
    public function of(User $user, Entry $entry): SocialCountsModel
    {
        $post = SocialPostAddress::of($entry)
            ?? throw new NotASocialPostException('This entry is not a Bluesky or Mastodon post.');
        $cached = $this->cache->find($post);
        if (null !== $cached) {
            return $cached;
        }

        try {
            $counts = $this->publicSourceOn($post->network)->countsOf($post->address);
        } catch (SocialPostNotFoundException | SocialNetworkUnreachableException $publicFailure) {
            $counts = $this->actingAccountCountsOf($user, $entry, $publicFailure);
        }
        $this->cache->remember($post, $counts);

        return $counts;
    }

    private function actingAccountCountsOf(
        User $user,
        Entry $entry,
        SocialPostNotFoundException|SocialNetworkUnreachableException $publicFailure,
    ): SocialCountsModel {
        try {
            $resolved = $this->posts->find($user, $entry);
        } catch (NoSocialAccountForNetworkException) {
            throw $publicFailure;
        }

        return $resolved->counts
            ?? throw new SocialNetworkUnreachableException('The network answered the post without its counts.');
    }

    private function publicSourceOn(SocialNetwork $network): PostCountsSourceInterface
    {
        $source = $this->publicSources->get($network->value);
        \assert($source instanceof PostCountsSourceInterface);

        return $source;
    }
}
