<?php

declare(strict_types=1);

namespace App\Service\Social\Model\SearchExpression;

use App\Service\Social\Model\SearchedPostModel;

final readonly class AuthorSearchModel implements SearchExpressionModelInterface
{
    public function __construct(private string $author)
    {
    }

    public function matches(SearchedPostModel $post): bool
    {
        return $post->isBy($this->author);
    }
}
