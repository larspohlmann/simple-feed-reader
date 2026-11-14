<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

enum FeedSyntax: string
{
    case Rss = 'rss';
    case Atom = 'atom';
}
