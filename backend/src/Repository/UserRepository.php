<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
     * Fetch by id or fail with a 404. Throwing the HTTP exception here keeps the
     * lookup-or-404 guard out of the admin controller.
     */
    public function getById(int $id): User
    {
        return $this->find($id) ?? throw new NotFoundHttpException('User not found.');
    }

    /**
     * The security layer's lookup, and the reason this repository implements
     * UserLoaderInterface at all.
     *
     * The Doctrine `entity` provider's `property: email` option queries the
     * submitted identifier verbatim. Since addresses are stored normalised,
     * someone who registered as `bob@` and typed `Bob@` would get a bare 401
     * with no explanation — a worse bug than the duplicate-account one
     * normalisation set out to fix, and one that would only surface for users
     * whose keyboard or mail client capitalises for them.
     *
     * Dropping `property` from security.yaml makes EntityUserProvider delegate
     * here instead, so login, JWT-driven reloads and every other lookup share
     * the entity's normalisation rather than reimplementing it.
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
     * The throwaway accounts the e2e suites leave behind: the backend suite
     * mints `e2e-…@example.com`, the Playwright onboarding journey
     * `onboarding-…@example.com`, and neither cleans up its own rows, so the
     * dev database accumulates them run after run (#184); both patterns end
     * in `@example.com`, so a real address can never match.
     * $protectedAdminEmail is excluded by name — the seeded admin shares the
     * `e2e-` prefix but the suites log in with it, so it is a fixture to
     * keep, not litter to collect.
     *
     * @return list<User>
     */
    public function findE2eFixtureAccounts(string $protectedAdminEmail): array
    {
        $qb = $this->createQueryBuilder('u');

        /** @var list<User> $fixtures */
        $fixtures = $qb
            ->andWhere($qb->expr()->orX(
                $qb->expr()->like('u.email', ':backendPattern'),
                $qb->expr()->like('u.email', ':playwrightPattern'),
            ))
            ->andWhere($qb->expr()->neq('u.email', ':protectedAdmin'))
            ->setParameter('backendPattern', 'e2e-%@example.com')
            ->setParameter('playwrightPattern', 'onboarding-%@example.com')
            ->setParameter('protectedAdmin', User::normalizeEmail($protectedAdminEmail))
            ->getQuery()
            ->getResult();

        return $fixtures;
    }

    /**
     * The admins to notify when a new account needs approving: those who can
     * actually act on it. A suspended or rejected admin is not a working
     * recipient, so active status gates the list the same way the firewall
     * gates the admin API.
     *
     * The role check runs in PHP, not the query: `roles` is portable
     * JSON-as-text on both SQLite (tests) and MySQL (prod), so a portable
     * `LIKE` would still need this same recheck to reject a
     * `ROLE_ADMINISTRATOR` substring. Loading the whole active userbase to
     * pick out a handful of admins is the real cost, acceptable since a
     * queue entry is rare (every account passes through a human; see
     * findForAdminList) and this runs off the request's critical path. If
     * the userbase outgrows memory, a `LIKE '%ROLE_ADMIN%'` prefilter
     * narrows hydration while keeping the recheck.
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

    /**
     * The bootstrap invariant: does an administrator exist yet? Any status
     * counts — gating on Active only would let a hijacker re-open first-run
     * setup by getting the sole admin suspended.
     *
     * `roles` is portable JSON-as-text on SQLite and MySQL, so the LIKE narrows
     * the hydration set but STILL needs the in-PHP recheck to reject a
     * `ROLE_ADMINISTRATOR` substring — the same reasoning as findActiveAdmins().
     */
    public function hasAnyAdmin(): bool
    {
        /** @var list<User> $candidates */
        $candidates = $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            if (\in_array('ROLE_ADMIN', $candidate->getRoles(), true)) {
                return true;
            }
        }

        return false;
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

    /**
     * How many administrators can actually act right now — the count
     * AccountDeleter::ensureNotTheLastAdmin() needs.
     *
     * Deliberately status-aware, unlike hasAnyAdmin(): that method protects a
     * different invariant (first-run setup must stay closed), and a suspended
     * admin still satisfies it by design, so a hijacker cannot re-open setup
     * by getting the sole admin suspended. This method protects "someone can
     * act" — a suspended admin cannot, since nothing short of shell access
     * flips their status back and `approve` sits behind ROLE_ADMIN on
     * `^/api/admin/`. Counting all statuses would let one admin suspend a
     * co-admin, then delete their own account, leaving a suspended admin
     * nobody can reinstate.
     *
     * The LIKE narrows the hydration set but STILL needs the in-PHP recheck to
     * reject a `ROLE_ADMINISTRATOR` substring — same reasoning as
     * findActiveAdmins() and hasAnyAdmin().
     */
    public function countActiveAdmins(): int
    {
        /** @var list<User> $active */
        $active = $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->andWhere('u.status = :active')
            ->setParameter('role', '%ROLE_ADMIN%')
            ->setParameter('active', UserStatus::Active)
            ->getQuery()
            ->getResult();

        return \count(array_filter(
            $active,
            static fn (User $candidate): bool => \in_array('ROLE_ADMIN', $candidate->getRoles(), true),
        ));
    }
}
