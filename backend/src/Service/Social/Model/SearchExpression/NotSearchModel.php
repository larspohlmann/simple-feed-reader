<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

final readonly class NotSearchModel implements SearchExpressionModelInterface
{
    public function __construct(private SearchExpressionModelInterface $operand)
    {
    }

    public function matches(string ...$lowerCasedTexts): bool
    {
        return !$this->operand->matches(...$lowerCasedTexts);
    }
}
