<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final readonly class NotificationPostKeys
{
    public function __construct(
        #[ORM\Column(name: 'post_key_hash', length: 64, nullable: true)]
        public ?string $postHash = null,
        #[ORM\Column(name: 'answered_key_hash', length: 64, nullable: true)]
        public ?string $answeredHash = null,
        #[ORM\Column(name: 'root_key_hash', length: 64, nullable: true)]
        public ?string $rootHash = null,
    ) {
    }

    /** @return list<string> */
    public function hashes(): array
    {
        return array_values(array_unique(array_filter(
            [$this->postHash, $this->answeredHash, $this->rootHash],
            static fn (?string $hash): bool => $hash !== null,
        )));
    }

    /** @param array<string, int> $entryIdsByHash */
    public function pickEntryId(array $entryIdsByHash): ?int
    {
        foreach ($this->hashes() as $hash) {
            if (isset($entryIdsByHash[$hash])) {
                return $entryIdsByHash[$hash];
            }
        }

        return null;
    }

    #[\NoDiscard]
    public function withAnswered(?string $answeredHash): self
    {
        return clone($this, ['answeredHash' => $answeredHash]);
    }
}
