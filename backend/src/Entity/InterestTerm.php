<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedLanguage;
use App\Repository\InterestTermRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One stem of a user's interest terms; a null language is the cut form. */
#[ORM\Entity(repositoryClass: InterestTermRepository::class)]
#[ORM\Table(name: 'interest_term')]
#[ORM\Index(name: 'idx_interest_term_user', columns: ['user_id'])]
final class InterestTerm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $stem;

    #[ORM\Column(length: 2, nullable: true, enumType: FeedLanguage::class)]
    private ?FeedLanguage $language;

    #[ORM\Column(type: Types::FLOAT)]
    private float $weight;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 1.0])]
    private float $rarity = 1.0;

    public function __construct(User $user, string $stem, ?FeedLanguage $language, float $weight)
    {
        $this->user = $user;
        $this->stem = $stem;
        $this->language = $language;
        $this->weight = $weight;
    }

    public function getStem(): string
    {
        return $this->stem;
    }

    public function getLanguage(): ?FeedLanguage
    {
        return $this->language;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getRarity(): float
    {
        return $this->rarity;
    }

    public function recordRarity(float $rarity): void
    {
        $this->rarity = $rarity;
    }
}
