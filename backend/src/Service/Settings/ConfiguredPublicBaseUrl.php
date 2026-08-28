<?php

declare(strict_types=1);

namespace App\Service\Settings;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves the public base URL from the admin-editable instance setting,
 * falling back to the APP_FRONTEND_URL deploy env when the admin has set none.
 * The result is memoised: one email may build many links, and the setting does
 * not change within a single send.
 */
final class ConfiguredPublicBaseUrl implements PublicBaseUrl
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
