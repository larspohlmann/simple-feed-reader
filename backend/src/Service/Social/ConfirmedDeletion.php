<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Enum\SourceFormat;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostRemovedException;
use App\Service\Social\HomeServerCheck\HomeServerCheckInterface;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Support\SocialPostAddress;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** A post its network could not find is removed here only when its home server confirms it deleted. */
final readonly class ConfirmedDeletion
{
    /** @param ServiceLocator<HomeServerCheckInterface> $homeServers */
    public function __construct(
        private LocalPostRemoval $localRemoval,
        #[AutowireLocator('app.social_home_server_check', defaultIndexMethod: 'network')]
        private ServiceLocator $homeServers,
    ) {
    }

    /**
     * Called after the post was not found; a saved copy outlives its original, so it is never removed.
     *
     * @throws SocialPostRemovedException when the post's home server confirms it deleted; every copy here is gone
     */
    public function removeIfConfirmed(Entry $entry): void
    {
        if (SourceFormat::isSaved($entry->getFeed()->getSourceFormat())) {
            return;
        }
        $post = SocialPostAddress::ofOrRefuse($entry);
        if (!$this->confirmsDeleted($post)) {
            return;
        }
        $this->localRemoval->removeWithoutReplies($entry);

        throw new SocialPostRemovedException(sprintf('The post was deleted on %s.', $post->network->displayName()));
    }

    private function confirmsDeleted(SocialPostAddressModel $post): bool
    {
        try {
            return $this->homeServers->get($post->network->value)->confirmsDeleted($post->address);
        } catch (SocialNetworkUnreachableException) {
            return false;
        }
    }
}
