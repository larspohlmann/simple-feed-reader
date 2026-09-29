<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\InstanceSetting;
use App\Entity\InstanceSettingsUpdate;
use App\Repository\InstanceSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The row is memoised per request and reset() drops it between worker messages: never promote it to a shared cache.
 * With no row yet, getters read an unpersisted InstanceSetting, so the entity's defaults are the only fallback.
 */
final class InstanceSettings implements ResetInterface
{
    private ?InstanceSetting $memoisedSettings = null;

    public function __construct(
        private readonly InstanceSettingRepository $storedSettings,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function requireEmailConfirmation(): bool
    {
        return $this->settings()->requireEmailConfirmation();
    }

    public function requireApproval(): bool
    {
        return $this->settings()->requireApproval();
    }

    public function getPublicBaseUrl(): ?string
    {
        return $this->settings()->getPublicBaseUrl();
    }

    public function getPasskeyRpId(): ?string
    {
        return $this->settings()->getPasskeyRpId();
    }

    public function getPasskeyRpName(): ?string
    {
        return $this->settings()->getPasskeyRpName();
    }

    public function passkeySignInEnabled(): bool
    {
        return $this->settings()->passkeySignInEnabled();
    }

    public function update(InstanceSettingsUpdate $update): void
    {
        $setting = $this->storedSettings->findSingleton();

        if (null === $setting) {
            $setting = new InstanceSetting();
            $this->entityManager->persist($setting);
        }

        $setting->apply($update);
        $this->entityManager->flush();
        $this->memoisedSettings = null;
    }

    public function reset(): void
    {
        $this->memoisedSettings = null;
    }

    private function settings(): InstanceSetting
    {
        return $this->memoisedSettings ??= $this->storedSettings->findSingleton() ?? new InstanceSetting();
    }
}
