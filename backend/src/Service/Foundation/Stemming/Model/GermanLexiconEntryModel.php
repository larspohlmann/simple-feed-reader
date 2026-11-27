<?php

declare(strict_types=1);

namespace App\Service\Foundation\Stemming\Model;

final readonly class GermanLexiconEntryModel
{
    private const string NOUN_KIND = 'n';

    public function __construct(private string $kind, public int $frequencyClass)
    {
    }

    public function isNoun(): bool
    {
        return self::NOUN_KIND === $this->kind;
    }
}
