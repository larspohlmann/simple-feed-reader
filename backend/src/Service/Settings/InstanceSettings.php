<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Entity\InstanceSetting;
use App\Repository\InstanceSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads and writes the instance-wide settings row. The rest of the app
 * depends on this, never on the entity or repository directly.
 *
 * `settings()` is where "no row yet" is handled: a fresh, unpersisted
 * InstanceSetting already carries exactly the defaults its properties
 * declare, so every getter below just reads off it instead of each one
 * carrying its own fallback that could drift from the entity.
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
        $setting = $this->repository->findSingleton();

        if (null === $setting) {
            $setting = new InstanceSetting();
            $this->em->persist($setting);
        }

        $setting->apply($update);
        $this->em->flush();
    }

    /**
     * Never persisted: a fresh InstanceSetting stands in for the no-row case
     * only for the span of one read. update() above has its own
     * findSingleton()-then-persist path and never calls this.
     */
    private function settings(): InstanceSetting
    {
        return $this->repository->findSingleton() ?? new InstanceSetting();
    }
}
