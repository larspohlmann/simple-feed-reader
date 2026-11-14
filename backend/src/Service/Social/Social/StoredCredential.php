<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Service\Foundation\Crypto\Exception\SecretUnreadableException;
use App\Service\Social\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;

final readonly class StoredCredential
{
    public function __construct(private SocialCredentialCipher $cipher)
    {
    }

    /** @throws SocialCredentialsRejectedException when the sealed secret no longer opens: only a reconnect helps */
    public function open(SocialAccount $account): string
    {
        try {
            return $this->cipher->open($account->getUser()->requireId(), $account->getSealedCredential());
        } catch (SecretUnreadableException $exception) {
            throw new SocialCredentialsRejectedException(
                'The stored credential can no longer be read; reconnect the account.',
                previous: $exception,
            );
        }
    }
}
