<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;

final readonly class BlueskySessions
{
    public function __construct(
        private BlueskySessionOpener $opener,
        private BlueskySessionCache $cache,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function current(SocialAccount $account): BlueskySessionModel
    {
        return $this->cache->find($account) ?? $this->fresh($account);
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function fresh(SocialAccount $account): BlueskySessionModel
    {
        $this->cache->forget($account);
        $session = $this->opener->openForAccount($account);
        $this->cache->remember($account, $session);

        return $session;
    }
}
