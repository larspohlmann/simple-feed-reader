<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A feed-declared category, shared globally: one (canonical_key, scheme) is one
 * row across every feed. Grouping and counting join on it; the label a reader
 * sees is the per-entry label on EntryCategory, not this identity row.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uniq_category_key_scheme', columns: ['canonical_key', 'scheme'])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'canonical_key', length: 128)]
    private string $canonicalKey;

    /**
     * The taxonomy the feed named (RSS domain / Atom scheme), or '' when none.
     * Empty string, never NULL: MySQL and SQLite both treat NULLs as distinct
     * in a unique index, which would let duplicate schemeless rows through.
     */
    #[ORM\Column(length: 255, options: ['default' => ''])]
    private string $scheme;

    public function __construct(string $canonicalKey, string $scheme)
    {
        $this->canonicalKey = $canonicalKey;
        $this->scheme = $scheme;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCanonicalKey(): string
    {
        return $this->canonicalKey;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }
}
