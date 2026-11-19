<?php

declare(strict_types=1);

namespace App\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Entity\SocialNotificationPoll;
use App\Repository\SocialNotificationPollRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Outbound\Fetch\HostThrottle;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NotificationPoll
{
    public function __construct(
        #[AutowireLocator('app.social_notification_source')]
        private ContainerInterface $sources,
        private ActingAccountGuard $guard,
        private NotificationStore $store,
        private NotificationContextLookup $contextLookup,
        private HostThrottle $hostThrottle,
        private SocialNotificationPollRepository $polls,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return int the rows stored that were not there before */
    public function poll(SocialAccount $account): int
    {
        $stamp = $this->polls->ensureStamp($account);
        if ($this->hostThrottle->remainingSeconds($account->getServiceUrl()) > 0) {
            $this->stampChecked($stamp);

            return 0;
        }

        try {
            $inserted = $this->store->record($account, $this->fetch($account));
        } catch (SocialTokenScopeMissingException) {
            $this->flushStamp($stamp->recordScopeMissing(...));

            return 0;
        } catch (SocialNetworkThrottledException $exception) {
            $this->hostThrottle->record($account->getServiceUrl(), $exception->retryAfterSeconds);

            $this->stampChecked($stamp);

            return 0;
        }
        $this->stampChecked($stamp);

        return $inserted;
    }

    private function fetch(SocialAccount $account): NotificationPageModel
    {
        $source = $this->sources->get($account->getNetwork()->value);
        \assert($source instanceof NotificationSourceInterface);

        return $this->guard->actAs(
            $account,
            fn (): NotificationPageModel => $this->contextLookup->completing($account, $source->fetch($account)),
        );
    }

    private function stampChecked(SocialNotificationPoll $stamp): void
    {
        $this->flushStamp($stamp->recordChecked(...));
    }

    /** @param \Closure(\DateTimeImmutable): void $record */
    private function flushStamp(\Closure $record): void
    {
        $record($this->clock->now());
        $this->entityManager->flush();
    }
}
