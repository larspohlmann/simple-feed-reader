<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryLabel;
use App\Entity\EntryState;
use App\Entity\Label;
use App\Entity\Subscription;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Library\Label\Model\LabelTalliesModel;
use App\Service\Library\Label\Model\LabelTallyModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Label>
 */
final class LabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Label::class);
    }

    public function findOneForUser(int $userId, int $labelId): ?Label
    {
        /** @var Label|null $label */
        $label = $this->createQueryBuilder('l')
            ->andWhere('l.id = :id')->setParameter('id', $labelId)
            ->andWhere('IDENTITY(l.user) = :user')->setParameter('user', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $label;
    }

    public function requireOneForUser(int $userId, int $labelId): Label
    {
        return $this->findOneForUser($userId, $labelId) ?? throw new RecordNotFoundException('No such label.');
    }

    public function findForUserByName(int $userId, string $name): ?Label
    {
        /** @var Label|null $label */
        $label = $this->createQueryBuilder('l')
            ->andWhere('IDENTITY(l.user) = :user')->setParameter('user', $userId)
            ->andWhere('LOWER(l.name) = LOWER(:name)')->setParameter('name', $name)
            ->orderBy('l.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $label;
    }

    public function existsForUserAndName(int $userId, string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('IDENTITY(l.user) = :user')->setParameter('user', $userId)
            ->andWhere('LOWER(l.name) = LOWER(:name)')->setParameter('name', $name);

        if (null !== $excludeId) {
            $qb->andWhere('l.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /** Counts cover still-subscribed feeds only, the scope of the Favorites and Kept lists. */
    public function countTalliesForUser(int $userId): LabelTalliesModel
    {
        return new LabelTalliesModel(
            $this->tallyLabels($userId),
            $this->countUnlabeled($userId, 'isFavorite'),
            $this->countUnlabeled($userId, 'isKept'),
        );
    }

    /** @return list<LabelTallyModel> */
    private function tallyLabels(int $userId): array
    {
        /** @var list<array{id: int|string, name: string, favorites: int|string|null, kept: int|string|null, articles: int|string|null}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS id', 'l.name AS name')
            ->addSelect('SUM(CASE WHEN s.id IS NOT NULL AND es.isFavorite = :true THEN 1 ELSE 0 END) AS favorites')
            ->addSelect('SUM(CASE WHEN s.id IS NOT NULL AND es.isKept = :true THEN 1 ELSE 0 END) AS kept')
            ->addSelect(
                'COUNT(DISTINCT NULLIF(CASE WHEN s.id IS NOT NULL AND (es.isFavorite = :true OR es.isKept = :true) '
                . 'THEN e.id ELSE 0 END, 0)) AS articles',
            )
            ->leftJoin(EntryLabel::class, 'el', Join::ON, 'el.label = l')
            ->leftJoin('el.entry', 'e')
            ->leftJoin(EntryState::class, 'es', Join::ON, 'es.entry = e AND IDENTITY(es.user) = :user')
            ->leftJoin(Subscription::class, 's', Join::ON, 's.feed = e.feed AND IDENTITY(s.user) = :user')
            ->andWhere('IDENTITY(l.user) = :user')
            ->setParameter('user', $userId)
            ->setParameter('true', true, Types::BOOLEAN)
            ->groupBy('l.id', 'l.name')
            ->orderBy('LOWER(l.name)', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): LabelTallyModel => new LabelTallyModel(
                (int) $row['id'],
                $row['name'],
                (int) $row['favorites'],
                (int) $row['kept'],
                (int) $row['articles'],
            ),
            $rows,
        );
    }

    /** @param 'isFavorite'|'isKept' $flag */
    private function countUnlabeled(int $userId, string $flag): int
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(es.entry)')
            ->from(EntryState::class, 'es')
            ->join('es.entry', 'e')
            ->join(Subscription::class, 's', Join::ON, 's.feed = e.feed AND IDENTITY(s.user) = :user')
            ->andWhere('IDENTITY(es.user) = :user')
            ->andWhere('es.' . $flag . ' = :true')
            ->andWhere($this->noLabelOfUserOn('es'))
            ->setParameter('user', $userId)
            ->setParameter('true', true, Types::BOOLEAN);

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    private function noLabelOfUserOn(string $stateAlias): string
    {
        $labelled = $this->getEntityManager()->createQueryBuilder()
            ->select('1')
            ->from(EntryLabel::class, 'lel')
            ->join('lel.label', 'll')
            ->where('lel.entry = ' . $stateAlias . '.entry')
            ->andWhere('IDENTITY(ll.user) = :user');

        return 'NOT EXISTS (' . $labelled->getDQL() . ')';
    }
}
