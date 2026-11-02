<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SourceFormat;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRemovedException;
use App\Service\Social\HomeServerCheck\HomeServerCheckInterface;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class OpenedPost
{
    public function __construct(
        private SocialLikes $likes,
        private LocalPostRemoval $localRemoval,
        #[AutowireLocator('app.social_home_server_check', defaultIndexMethod: 'network')]
        private ContainerInterface $homeServers,
    ) {
    }

    /**
     * @throws SocialPostRemovedException when the post's home server confirms it deleted; every copy here is gone
     * @throws SocialPostNotFoundException when the acting account cannot find the post and nothing proves it deleted,
     *                                     or the entry is a saved copy, which outlives its original
     */
    public function sync(User $user, Entry $entry): SocialPostModel
    {
        try {
            return $this->likes->syncFromNetwork($user, $entry);
        } catch (SocialPostNotFoundException $notFound) {
            $this->removeIfDeleted($entry);

            throw $notFound;
        }
    }

    /** @throws SocialPostRemovedException */
    private function removeIfDeleted(Entry $entry): void
    {
        if (SourceFormat::isSaved($entry->getFeed()->getSourceFormat())) {
            return;
        }
        $post = SocialPostAddress::ofOrRefuse($entry);
        if (!$this->confirmsDeleted($post)) {
            return;
        }
        // Only the post is proven gone; its thread replies may still exist, so none is tombstoned.
        $this->localRemoval->remove($entry, []);

        throw new SocialPostRemovedException(sprintf('The post was deleted on %s.', $post->network->displayName()));
    }

    private function confirmsDeleted(SocialPostAddressModel $post): bool
    {
        try {
            return $this->homeServerOn($post->network)->confirmsDeleted($post->address);
        } catch (SocialNetworkUnreachableException) {
            return false;
        }
    }

    private function homeServerOn(SocialNetwork $network): HomeServerCheckInterface
    {
        $check = $this->homeServers->get($network->value);
        \assert($check instanceof HomeServerCheckInterface);

        return $check;
    }
}
