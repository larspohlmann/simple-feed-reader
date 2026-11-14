<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DigestSettings;
use App\Service\Outbound\Mail\Digest\DigestRecipients\DigestRecipientsInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DigestSettings> */
final class DigestSettingsRepository extends ServiceEntityRepository implements DigestRecipientsInterface
{
    /** @use UserSettingsRowLookup<DigestSettings> */
    use UserSettingsRowLookup;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DigestSettings::class);
    }

    /**
     * Every row with the digest enabled, joined to its user, for the scheduler to test dueness against.
     * Enabled is the only cheap pre-filter; dueness needs the timezone maths, so it is applied in PHP.
     *
     * @return list<DigestSettings>
     */
    public function findWithDigestEnabled(): array
    {
        /** @var list<DigestSettings> $rows */
        $rows = $this->createQueryBuilder('d')
            // Fetch-join the user the sweep reads for every due row, so dueness
            // and eligibility do not each fire a lazy-load SELECT per account.
            ->addSelect('u')
            ->join('d.user', 'u')
            ->andWhere('d.enabled = true')
            ->getQuery()->getResult();

        return $rows;
    }
}
