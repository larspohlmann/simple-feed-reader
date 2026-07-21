<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
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
}
