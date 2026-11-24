<?php

declare(strict_types=1);

namespace App\Service\Content\Comments;

use App\Service\Content\Comments\Model\CommentBodyFormat;
use App\Service\Content\Comments\Model\CommentBodyModel;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Text\MarkdownHtml;
use App\Service\Foundation\Text\Support\LinkedPlainText;

final readonly class CommentBodyRenderer
{
    public function __construct(private MarkdownHtml $markdown, private EntrySanitizer $sanitizer)
    {
    }

    public function buildHtml(CommentBodyModel $body): ?string
    {
        return $this->sanitizer->sanitize($this->convertToHtml($body));
    }

    private function convertToHtml(CommentBodyModel $body): ?string
    {
        return match ($body->format) {
            CommentBodyFormat::Html => $body->source,
            CommentBodyFormat::Markdown => $this->markdown->toHtml($body->source),
            CommentBodyFormat::PlainText => LinkedPlainText::asHtml($body->source),
        };
    }
}
