<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedLanguage;

final readonly class InterestPhrase
{
    public function __construct(public FeedLanguage $language, public string $phrase, public float $weight)
    {
    }

    /** @return array{language: string, phrase: string, weight: float} */
    public function toStoredArray(): array
    {
        return ['language' => $this->language->value, 'phrase' => $this->phrase, 'weight' => $this->weight];
    }

    /** @param array<mixed> $stored */
    public static function fromStoredArray(array $stored): self
    {
        $language = $stored['language'] ?? null;
        $phrase = $stored['phrase'] ?? null;
        $weight = $stored['weight'] ?? null;
        if (!\is_string($language) || !\is_string($phrase) || !is_numeric($weight)) {
            throw new \InvalidArgumentException('A stored interest phrase needs a language, a phrase and a weight.');
        }

        return new self(FeedLanguage::from($language), $phrase, (float) $weight);
    }
}
