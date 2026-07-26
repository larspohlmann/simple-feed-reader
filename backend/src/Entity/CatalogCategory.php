<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CatalogCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One group in the onboarding catalog. Its name becomes the name of the Tag a
 * subscribing user gets, and its colour and icon seed that tag on creation —
 * which is why the same validation applies here as to CreateTagRequest.
 */
#[ORM\Entity(repositoryClass: CatalogCategoryRepository::class)]
#[ORM\Table(name: 'catalog_category')]
#[ORM\UniqueConstraint(name: 'uniq_catalog_category_key', columns: ['category_key'])]
class CatalogCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'category_key', length: 64)]
    private string $key;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $icon;

    #[ORM\Column(length: 7)]
    private string $color;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /**
     * A locked row is the admin's, not the catalog document's: an import will
     * neither overwrite nor delete it. See CatalogImporter.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $locked = false;

    /** @var Collection<int, CatalogFeed> */
    #[ORM\OneToMany(targetEntity: CatalogFeed::class, mappedBy: 'category')]
    #[ORM\OrderBy(['position' => 'ASC', 'title' => 'ASC'])]
    private Collection $feeds;

    public function __construct(string $key, string $name, string $icon, string $color)
    {
        $this->key = $key;
        $this->name = $name;
        $this->icon = $icon;
        $this->color = $color;
        $this->feeds = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function setIcon(string $icon): void
    {
        $this->icon = $icon;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): void
    {
        $this->color = $color;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function setLocked(bool $locked): void
    {
        $this->locked = $locked;
    }

    /**
     * Only the feeds a user may actually pick. Disabling a feed is how a retired
     * source leaves the picker without deleting a row an admin may have edited.
     *
     * @return list<CatalogFeed>
     */
    public function getEnabledFeeds(): array
    {
        return array_values(
            array_filter(
                $this->feeds->toArray(),
                static fn (CatalogFeed $feed): bool => $feed->isEnabled(),
            ),
        );
    }
}
