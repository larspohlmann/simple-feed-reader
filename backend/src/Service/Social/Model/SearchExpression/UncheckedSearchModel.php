<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

/** What the reader cannot check in a post's text, a network-side filter such as `from:alice`: it never narrows. */
final readonly class UncheckedSearchModel implements SearchExpressionModelInterface
{
    public function matches(string ...$lowerCasedTexts): bool
    {
        return true;
    }
}
