<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Social\AccountVerifier\AccountVerifierInterface;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Exception\SocialAccountAlreadyConnectedException;
use App\Service\Social\Exception\SocialAccountMismatchException;
use App\Service\Social\Exception\TooManySocialAccountsException;
use App\Service\Social\Factory\SocialAccountFactory;
use App\Service\Social\Model\PrivateFeedModel;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use App\Service\Subscription\Exception\SubscriptionLimitReachedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class SocialAccountConnector
{
    private const int MAX_ACCOUNTS = 20;

    public function __construct(
        private AccountVerifierInterface $verifier,
        private SocialAccountRepository $socialAccounts,
        private SocialAccountFactory $accountFactory,
        private SocialCredentialCipher $cipher,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
        private PrivateFeeds $privateFeeds,
    ) {
    }

    public function connect(User $user, SocialCredentialsModel $credentials): SocialAccount
    {
        if ($this->socialAccounts->countForUser($user) >= self::MAX_ACCOUNTS) {
            throw new TooManySocialAccountsException(
                'This account already holds the maximum number of social accounts.',
            );
        }

        $verified = $this->verifier->verify($credentials);
        if (null !== $this->socialAccounts->findOneByIdentity($user, $credentials->network, $verified->identity)) {
            throw self::alreadyConnected($verified);
        }

        $account = $this->accountFactory->create($user, $credentials, $verified);
        if ([] === $this->socialAccounts->findAllOnNetwork($user, $credentials->network)) {
            $account->becomeDefault();
        }

        $this->entityManager->persist($account);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw self::alreadyConnected($verified, $exception);
        }

        $this->createHome($account);

        return $account;
    }

    public function reconnect(SocialAccount $account, SocialCredentialsModel $credentials): SocialAccount
    {
        $verified = $this->verifier->verify($credentials);
        if ($verified->identity !== $account->getIdentity()) {
            throw new SocialAccountMismatchException(
                sprintf('This credential belongs to %s, not %s.', $verified->handle, $account->getHandle()),
            );
        }

        $account->replaceCredential(
            $verified->handle,
            $this->cipher->seal($account->getUser()->requireId(), $credentials->secret),
            $credentials->hint(),
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC')),
        );
        $account->moveTo($verified->serviceUrl);
        $this->privateFeeds->resume($account);
        $this->entityManager->flush();

        return $account;
    }

    private function createHome(SocialAccount $account): void
    {
        try {
            $this->privateFeeds->create($account, PrivateFeedModel::home());
        } catch (SubscriptionLimitReachedException) {
            // A full account still connects; the import picker offers Home again once a slot is free.
        }
    }

    private static function alreadyConnected(
        VerifiedAccountModel $verified,
        ?\Throwable $previous = null,
    ): SocialAccountAlreadyConnectedException {
        return new SocialAccountAlreadyConnectedException(
            sprintf('%s is already connected.', $verified->handle),
            previous: $previous,
        );
    }
}
