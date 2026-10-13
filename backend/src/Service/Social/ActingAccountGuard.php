<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ActingAccountGuard
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     *
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialCredentialsRejectedException once the account is marked as needing a reconnect
     */
    public function actAs(SocialAccount $account, \Closure $call): mixed
    {
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            throw new SocialAccountNeedsReconnectException(
                sprintf('%s needs reconnecting before it can act on a post.', $account->getHandle()),
            );
        }

        try {
            return $call();
        } catch (SocialCredentialsRejectedException $exception) {
            $account->markNeedsReconnect();
            $this->entityManager->flush();

            throw $exception;
        }
    }
}
