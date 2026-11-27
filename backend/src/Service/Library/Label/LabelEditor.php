<?php

declare(strict_types=1);

namespace App\Service\Library\Label;

use App\Entity\Label;
use App\Entity\User;
use App\Repository\LabelRepository;
use App\Service\Library\Label\Exception\LabelNameTakenException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LabelEditor
{
    public function __construct(
        private LabelRepository $labels,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(User $user, string $name): Label
    {
        if ($this->labels->existsForUserAndName($user->requireId(), $name)) {
            throw new LabelNameTakenException();
        }

        return $this->persistNew($user, $name);
    }

    public function ensureNamed(User $user, string $name): Label
    {
        return $this->labels->findForUserByName($user->requireId(), $name) ?? $this->persistNew($user, $name);
    }

    public function rename(Label $label, string $name): void
    {
        if ($this->labels->existsForUserAndName($label->getUser()->requireId(), $name, $label->requireId())) {
            throw new LabelNameTakenException();
        }

        $label->rename($name);
        $this->entityManager->flush();
    }

    public function delete(Label $label): void
    {
        $this->entityManager->remove($label);
        $this->entityManager->flush();
    }

    private function persistNew(User $user, string $name): Label
    {
        $label = new Label($user, $name);
        $this->entityManager->persist($label);
        $this->entityManager->flush();

        return $label;
    }
}
