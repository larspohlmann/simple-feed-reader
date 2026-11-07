<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

final readonly class AnyOfSearchModel implements SearchExpressionModelInterface
{
    /** @param list<SearchExpressionModelInterface> $operands */
    public function __construct(private array $operands)
    {
    }

    public function matches(string ...$lowerCasedTexts): bool
    {
        foreach ($this->operands as $operand) {
            if ($operand->matches(...$lowerCasedTexts)) {
                return true;
            }
        }

        return false;
    }
}
