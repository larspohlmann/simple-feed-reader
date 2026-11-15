<?php

declare(strict_types=1);

namespace App\Service\Operations\LinkLanguageFill\Model;

use App\Enum\FeedLanguage;

final readonly class LinkLanguageFillReportModel
{
    /** @param array<string, int> $filledByLanguage rows given a language, by its code, in code order */
    public function __construct(
        public array $filledByLanguage = [],
        public int $leftWithout = 0,
    ) {
    }

    #[\NoDiscard]
    public function filled(FeedLanguage $language): self
    {
        $filled = $this->filledByLanguage;
        $filled[$language->value] = ($filled[$language->value] ?? 0) + 1;
        ksort($filled);

        return clone($this, ['filledByLanguage' => $filled]);
    }

    #[\NoDiscard]
    public function leftShort(): self
    {
        return clone($this, ['leftWithout' => $this->leftWithout + 1]);
    }

    #[\NoDiscard]
    public function plus(self $other): self
    {
        $filled = $this->filledByLanguage;
        foreach ($other->filledByLanguage as $language => $count) {
            $filled[$language] = ($filled[$language] ?? 0) + $count;
        }
        ksort($filled);

        return new self($filled, $this->leftWithout + $other->leftWithout);
    }

    public function filledCount(): int
    {
        return array_sum($this->filledByLanguage);
    }

    public function filledIn(FeedLanguage $language): int
    {
        return $this->filledByLanguage[$language->value] ?? 0;
    }
}
