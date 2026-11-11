<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\DigestSettings;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Mail\Digest\Model\DigestConfigurationModel;

/**
 * Applies a digest configuration write. The first enable (no lastSentAt yet) seeds it to now, so the first
 * digest covers only entries that arrive after opt-in, not the account's whole backlog.
 */
final readonly class DigestEnablement
{
    public function __construct(
        private NaiveUtcClock $clock,
    ) {
    }

    public function applyTo(DigestSettings $settings, DigestConfigurationModel $configuration): void
    {
        $wasEnabled = $settings->isEnabled();

        $settings->setEnabled($configuration->enabled);
        $settings->setCadence($configuration->cadence);
        $settings->setSendHour($configuration->sendHour);
        $settings->setWeekday($configuration->weekday);
        $settings->setFormat($configuration->format);

        if ($this->isFirstEnable($wasEnabled, $configuration->enabled, $settings)) {
            $settings->setLastSentAt($this->clock->now());
        }
    }

    private function isFirstEnable(bool $wasEnabled, bool $isNowEnabled, DigestSettings $settings): bool
    {
        return false === $wasEnabled
            && true === $isNowEnabled
            && null === $settings->getLastSentAt();
    }
}
