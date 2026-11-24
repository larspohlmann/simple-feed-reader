<?php

declare(strict_types=1);

namespace App\Service\Content\Comments\Model;

enum CommentBodyFormat
{
    case Html;
    case Markdown;
    case PlainText;
}
