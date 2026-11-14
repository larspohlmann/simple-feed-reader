<?php

declare(strict_types=1);

namespace App\Service\Crypto\Model;

/**
 * What a secret is and whose it is. render()'s string is part of the stored format: changing it makes every existing
 * row unreadable.
 */
final readonly class SecretBindingModel
{
    private const string INSTANCE_SCOPE = 'instance';

    private function __construct(
        private string $purpose,
        private string $scope,
    ) {
    }

    public static function forInstance(string $purpose): self
    {
        return new self($purpose, self::INSTANCE_SCOPE);
    }

    public static function forUser(string $purpose, int $userId): self
    {
        return new self($purpose, sprintf('user:%d', $userId));
    }

    public function render(int $version): string
    {
        return sprintf('%s|v%d|%s', $this->purpose, $version, $this->scope);
    }
}
