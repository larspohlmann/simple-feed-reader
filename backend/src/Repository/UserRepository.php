<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Auth\UserByEmail\UserByEmailInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository implements UserLoaderInterface, UserByEmailInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => User::normalizeEmail($email)]);
    }

    public function getById(int $id): User
    {
        return $this->find($id) ?? throw new RecordNotFoundException('User not found.');
    }

    /**
     * The user provider's lookup: security.yaml's entity provider names no `property`, so login and JWT reloads come
     * here and share the entity's email normalisation.
     */
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->findOneByEmail($identifier);
    }

    /**
     * The admin queue, oldest first: whoever waited longest is on top. Unpaginated on purpose, since every account
     * passes through a human; if it outgrows a scroll, paginate rather than LIMIT here.
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
     * The accounts the e2e suites leave behind (`e2e-…` and `onboarding-…`, both `@example.com`, so no real address
     * matches). $protectedAdminEmail shares the `e2e-` prefix but is the admin the suites log in with: it stays.
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
     * The active admins, who get the new-account notice. Roles are checked in PHP: `roles` is JSON text on both
     * engines, so a LIKE would also match `ROLE_ADMINISTRATOR`. Loading every active user is accepted: approvals are
     * rare and off the request path.
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
            static fn (User $user): bool => $user->isAdmin(),
        ));
    }

    /**
     * Whether first-run setup is closed. Any status counts: counting active admins only would let whoever gets the
     * sole admin suspended re-open setup. The LIKE only narrows; isAdmin() rejects `ROLE_ADMINISTRATOR`.
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
            if ($candidate->isAdmin()) {
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
     * The admins who can act, for AccountDeleter::ensureNotTheLastAdmin(). Active only, unlike hasAnyAdmin(): only a
     * shell reinstates a suspended admin, so counting them would let the last working admin delete their account.
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
            static fn (User $candidate): bool => $candidate->isAdmin(),
        ));
    }
}
