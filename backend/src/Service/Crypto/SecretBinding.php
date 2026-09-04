<?php

declare(strict_types=1);

namespace App\Service\Crypto;

/**
 * Names what a secret is and whom it belongs to. Both halves feed the key
 * derivation and the AEAD's additional data, so a ciphertext cannot be read as
 * another kind of secret or moved to another owner. The rendered string is
 * part of the stored contract: change it and every existing row stops opening.
 */
final readonly class SecretBinding
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
