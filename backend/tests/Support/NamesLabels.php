<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Label;
use App\Entity\User;
use App\Repository\LabelRepository;
use Doctrine\ORM\EntityManagerInterface;

/** The user's stored label of a name, created and flushed when the user has none yet. For a kernel test case. */
trait NamesLabels
{
    private function labelNamed(User $user, string $name): Label
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $labels = $entityManager->getRepository(Label::class);
        self::assertInstanceOf(LabelRepository::class, $labels);

        $label = $labels->findForUserByName($user->requireId(), $name);
        if (null !== $label) {
            return $label;
        }
        $label = new Label($user, $name);
        $entityManager->persist($label);
        $entityManager->flush();

        return $label;
    }
}
