<?php

declare(strict_types=1);

namespace App\Service\Settings\PublicBaseUrl;

use App\Service\Settings\InstanceSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/** The admin's setting, else APP_FRONTEND_URL. Memoised until reset(): one send builds many links. */
final class ConfiguredPublicBaseUrl implements PublicBaseUrlInterface, ResetInterface
{
    private ?string $resolved = null;

    public function __construct(
        private readonly InstanceSettings $settings,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private readonly string $fallback,
    ) {
    }

    public function get(): string
    {
        return $this->resolved ??= rtrim($this->configured() ?? $this->fallback, '/');
    }

    public function reset(): void
    {
        $this->resolved = null;
    }

    private function configured(): ?string
    {
        $configured = $this->settings->getPublicBaseUrl();
        if (null === $configured) {
            return null;
        }

        $trimmed = trim($configured);

        return '' === $trimmed ? null : $trimmed;
    }
}
