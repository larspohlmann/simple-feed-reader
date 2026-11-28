<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Entity\User;
use App\Entity\YourStreamSettings;
use App\Entity\YourStreamTuning;
use App\Repository\YourStreamSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class YourStreamSettingsWriter
{
    public function __construct(
        private YourStreamSettingsRepository $streamSettings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user, YourStreamTuning $tuning): void
    {
        $settings = $this->streamSettings->findForUser($user);
        if (null === $settings) {
            $this->entityManager->persist(new YourStreamSettings($user, $tuning));
        } else {
            $settings->changeTuning($tuning);
        }
        $this->entityManager->flush();
    }
}
