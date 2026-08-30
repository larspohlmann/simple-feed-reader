<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\InstanceSetting;
use App\Repository\InstanceSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide settings row, defaulting to "both gates on"
 * when no row exists. The rest of the app depends on this, never on the entity
 * or repository directly, so "no row yet" is handled in exactly one place.
 */
final readonly class InstanceSettings
{
    public function __construct(
        private InstanceSettingRepository $repository,
        private EntityManagerInterface $em,
    ) {
    }

    public function requireEmailConfirmation(): bool
    {
        return $this->repository->findSingleton()?->requireEmailConfirmation() ?? true;
    }

    public function requireApproval(): bool
    {
        return $this->repository->findSingleton()?->requireApproval() ?? true;
    }

    public function getPublicBaseUrl(): ?string
    {
        return $this->repository->findSingleton()?->getPublicBaseUrl();
    }

    public function getPasskeyRpId(): ?string
    {
        return $this->repository->findSingleton()?->getPasskeyRpId();
    }

    public function getPasskeyRpName(): ?string
    {
        return $this->repository->findSingleton()?->getPasskeyRpName();
    }

    /**
     * `?? false`, not `?? true` like the two gates above (#624 follow-up,
     * addendum): the no-row case must agree with the column's own DEFAULT,
     * and both now say "off". See InstanceSetting::$passkeySignInEnabled's
     * docblock for the full list of five places this default has to match.
     */
    public function passkeySignInEnabled(): bool
    {
        return $this->repository->findSingleton()?->passkeySignInEnabled() ?? false;
    }

    public function update(InstanceSettingsUpdate $update): void
    {
        $setting = $this->repository->findSingleton();

        if (null === $setting) {
            $setting = new InstanceSetting();
            $this->em->persist($setting);
        }

        $setting->apply($update);
        $this->em->flush();
    }
}
