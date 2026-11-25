<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Pass;

use App\Entity\Tag;

final readonly class OpmlChosenTags
{
    /**
     * @param array<string, list<Tag>> $replacing
     * @param array<string, list<Tag>> $adding
     */
    public function __construct(private array $replacing, private array $adding)
    {
    }

    /** @return list<Tag>|null */
    public function findReplacing(string $feedUrl): ?array
    {
        return $this->replacing[$feedUrl] ?? null;
    }

    /** @return list<Tag> */
    public function findAdding(string $feedUrl): array
    {
        return $this->adding[$feedUrl] ?? [];
    }
}
