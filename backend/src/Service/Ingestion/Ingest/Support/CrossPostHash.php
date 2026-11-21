<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Support;

use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Foundation\Text\Support\Whitespace;
use App\Service\Ingestion\Ingest\Model\CrossPostModel;

final class CrossPostHash
{
    public static function build(CrossPostModel $post): ?string
    {
        if ($post->authorUrl === null || $post->authorUrl === '') {
            return null;
        }
        $author = rtrim(mb_strtolower($post->authorUrl), '/');
        if ($post->normalizedLinkedUrl !== null && $post->normalizedLinkedUrl !== '') {
            return hash('sha256', "link\n" . $author . "\n" . $post->normalizedLinkedUrl);
        }
        $title = mb_strtolower(Whitespace::collapse($post->title));
        $body = mb_strtolower((string) PlainText::fromHtmlBlocks($post->bodyHtml));
        if ($title === '' && $body === '') {
            return null;
        }

        return hash('sha256', "text\n" . $author . "\n" . $title . "\n" . $body);
    }

    private function __construct()
    {
    }
}
