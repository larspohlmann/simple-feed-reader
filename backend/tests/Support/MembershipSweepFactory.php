<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\EntryMembershipSweepRepository;
use App\Repository\SavedSearchEntryMembershipRepository;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\SavedSearchMatcher;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\Container\ContainerInterface;

/**
 * A membership sweep over the test container's real repositories and a
 * caller-chosen matcher, clock and logger — the one assembly every sweep
 * test, the handler test and the maintenance-tick test need.
 */
final class MembershipSweepFactory
{
    public static function fromContainer(
        ContainerInterface $container,
        EntityManagerInterface $em,
        SavedSearchMatcher $matcher,
        ClockInterface $clock,
        ?LoggerInterface $logger = null,
        ?SavedSearchEntryMembershipRepository $memberships = null,
    ): SavedSearchMembershipSweep {
        return new SavedSearchMembershipSweep(
            self::service($container, SavedSearchRepository::class),
            self::service($container, EntryMembershipSweepRepository::class),
            $memberships ?? self::service($container, SavedSearchEntryMembershipRepository::class),
            $matcher,
            $em,
            $clock,
            $logger ?? new NullLogger(),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(ContainerInterface $container, string $class): object
    {
        $service = $container->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException($class . ' is not in the test container.');
        }

        return $service;
    }
}
