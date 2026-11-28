<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedLanguage;
use App\Repository\YourStreamWordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** One stem of an account's Your stream word list; a null language is the cut form, the word what the reader sees. */
#[ORM\Entity(repositoryClass: YourStreamWordRepository::class)]
#[ORM\Table(name: 'your_stream_word')]
#[ORM\Index(name: 'idx_your_stream_word_user', columns: ['user_id'])]
final class YourStreamWord
{
    public const int STEM_MAX_LENGTH = 64;
    public const int WORD_MAX_LENGTH = 100;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 2, nullable: true, enumType: FeedLanguage::class)]
    private ?FeedLanguage $language;

    #[ORM\Column(length: self::STEM_MAX_LENGTH)]
    private string $stem;

    #[ORM\Column(length: self::WORD_MAX_LENGTH)]
    private string $word;

    #[ORM\Column(type: Types::FLOAT)]
    private float $weight;

    #[ORM\Column(type: Types::FLOAT)]
    private float $rarity;

    public function __construct(
        User $user,
        ?FeedLanguage $language,
        string $stem,
        string $word,
        float $weight,
        float $rarity,
    ) {
        $this->user = $user;
        $this->language = $language;
        $this->stem = $stem;
        $this->word = $word;
        $this->weight = $weight;
        $this->rarity = $rarity;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLanguage(): ?FeedLanguage
    {
        return $this->language;
    }

    public function getStem(): string
    {
        return $this->stem;
    }

    public function getWord(): string
    {
        return $this->word;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getRarity(): float
    {
        return $this->rarity;
    }
}
