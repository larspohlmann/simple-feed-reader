<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\User;
use App\Repository\TagTreeRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Every writer that moves a tag under another holds this, or two crossing moves can each pass and loop. */
final readonly class TagStructureLock
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TagTreeRepository $tagTrees,
    ) {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function hold(User $user, \Closure $work): mixed
    {
        $this->entityManager->beginTransaction();
        try {
            $this->tagTrees->lockTagsOf($user->requireId());
            $result = $work();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        return $result;
    }
}
