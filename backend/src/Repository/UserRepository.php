<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    /**
     * The security layer's lookup, and the reason this repository implements
     * UserLoaderInterface at all.
     *
     * The Doctrine `entity` provider's `property: email` option queries the
     * submitted identifier verbatim. Since addresses are stored normalised,
     * that would mean someone who registered as `bob@` and typed `Bob@` at the
     * login form got a bare 401 with nothing to explain it — a worse bug than
     * the duplicate-account one normalisation set out to fix, and one that
     * would surface only for users whose keyboard or mail client capitalises
     * for them.
     *
     * Dropping `property` from security.yaml makes EntityUserProvider delegate
     * here instead, so login, JWT-driven reloads and every other provider
     * lookup share the entity's normalisation rather than reimplementing it.
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->findOneByEmail($identifier);
    }

    /**
     * The admin queue's listing. Oldest first, because the queue is worked
     * front to back and the person who has waited longest should be on top.
     *
     * Unpaginated on purpose: the instance has no user cap but also no growth
     * engine — every account passes through a human. If this ever returns more
     * rows than an admin can scroll, pagination is the fix, not a LIMIT here.
     *
     * @param list<UserStatus>|null $statuses
     *
     * @return list<User>
     */
    public function findForAdminList(?array $statuses = null): array
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.createdAt', 'ASC');

        if (null !== $statuses && [] !== $statuses) {
            $qb->andWhere('u.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        /** @var list<User> $users */
        $users = $qb->getQuery()->getResult();

        return $users;
    }

    /**
     * Feeds the purge command: accounts that never confirmed their address and
     * are past the grace period, so the address can be released for its real
     * owner to register.
     *
     * @return list<User>
     */
    public function findUnverifiedCreatedBefore(\DateTimeImmutable $cutoff): array
    {
        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('u.status = :status')
            ->andWhere('u.createdAt < :cutoff')
            ->setParameter('status', UserStatus::PendingVerification)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        return $users;
    }

    /**
     * The admins to notify when a new account needs approving: those who can
     * actually act on it. A suspended or rejected admin is not a working
     * recipient, so active status gates the list the same way the firewall
     * gates the admin API.
     *
     * The role check is done in PHP rather than in the query on purpose. `roles`
     * is a portable JSON-as-text column on both SQLite (tests) and MySQL (prod),
     * so a portable role query would be a `LIKE` that STILL needs this same
     * in-PHP recheck to reject a `ROLE_ADMINISTRATOR` substring. This loads the
     * whole active userbase to pick out the handful of admins, which is the real
     * cost — acceptable because a queue-entry is a rare event (a human approves
     * every account, so there is no growth engine driving the active set; see
     * findForAdminList) and this runs off the request's critical path. If that
     * userbase ever outgrows memory, a `LIKE '%ROLE_ADMIN%'` prefilter narrows
     * the hydration set while keeping the recheck.
     *
     * @return list<User>
     */
    public function findActiveAdmins(): array
    {
        /** @var list<User> $active */
        $active = $this->createQueryBuilder('u')
            ->andWhere('u.status = :active')
            ->setParameter('active', UserStatus::Active)
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $active,
            static fn (User $user): bool => \in_array('ROLE_ADMIN', $user->getRoles(), true),
        ));
    }

    public function countByStatus(UserStatus $status): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
