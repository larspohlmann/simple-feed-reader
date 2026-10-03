<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** The account's latest generated profile; a failed profile run never replaces it. */
#[ORM\Embeddable]
final class StoredProfile
{
    #[ORM\Column(name: 'profile_text', type: Types::TEXT, nullable: true)]
    private ?string $text;

    #[ORM\Column(name: 'profile_generated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $generatedAt;

    #[ORM\Column(name: 'profile_provider_host', length: 255, nullable: true)]
    private ?string $providerHost;

    #[ORM\Column(name: 'profile_model', length: 255, nullable: true)]
    private ?string $model;

    public function __construct(
        ?string $text,
        ?\DateTimeImmutable $generatedAt,
        ?string $providerHost,
        ?string $model,
    ) {
        $this->text = $text;
        $this->generatedAt = $generatedAt;
        $this->providerHost = $providerHost;
        $this->model = $model;
    }

    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function getGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getProviderHost(): ?string
    {
        return $this->providerHost;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }
}
