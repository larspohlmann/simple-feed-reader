<?php

declare(strict_types=1);

namespace App\Service\Social\Factory;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use Psr\Clock\ClockInterface;

final readonly class SocialAccountFactory
{
    public function __construct(
        private SocialCredentialCipher $cipher,
        private ClockInterface $clock,
    ) {
    }

    public function create(
        User $user,
        SocialCredentialsModel $credentials,
        VerifiedAccountModel $verified,
    ): SocialAccount {
        return new SocialAccount(
            $user,
            $credentials->network,
            $verified->identity,
            $verified->handle,
            $verified->serviceUrl,
            $this->cipher->seal($user->requireId(), $credentials->secret),
            $credentials->hint(),
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC')),
        );
    }
}
