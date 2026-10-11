<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class EntryGuid
{
    #[ORM\Column(name: 'guid', type: Types::TEXT)]
    private string $value;

    #[ORM\Column(name: 'guid_hash', length: 64)]
    private string $hash;

    public function __construct(string $guid)
    {
        $this->value = $guid;
        $this->hash = hash('sha256', $guid);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getHash(): string
    {
        return $this->hash;
    }
}
