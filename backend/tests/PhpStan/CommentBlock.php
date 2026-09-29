<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

final readonly class CommentBlock
{
    public function __construct(public int $firstLine, public int $proseLines)
    {
    }
}
