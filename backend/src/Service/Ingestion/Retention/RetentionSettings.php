<?php

declare(strict_types=1);

namespace App\Service\Retention;

use App\Entity\RetentionSetting;
use App\Entity\RetentionWindows;
use App\Service\Retention\StoredRetentionSetting\StoredRetentionSettingInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RetentionSettings
{
    public function __construct(
        private StoredRetentionSettingInterface $storedSettings,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function current(): RetentionWindows
    {
        return ($this->storedSettings->findSingleton() ?? new RetentionSetting())->getWindows();
    }

    public function update(RetentionWindows $windows): void
    {
        $setting = $this->storedSettings->findSingleton();

        if (null === $setting) {
            $setting = new RetentionSetting();
            $this->entityManager->persist($setting);
        }

        $setting->apply($windows);
        $this->entityManager->flush();
    }
}
