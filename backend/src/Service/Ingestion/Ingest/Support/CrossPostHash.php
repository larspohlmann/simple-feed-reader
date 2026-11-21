<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Support;

use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Ingestion\Ingest\Model\CrossPostModel;

final class CrossPostHash
{
    public static function build(CrossPostModel $post): ?string
    {
        if ($post->authorUrl === null) {
            return null;
        }
        $author = rtrim(mb_strtolower($post->authorUrl), '/');
        if ($post->normalizedLinkedUrl !== null) {
            return hash('sha256', "link\n" . $author . "\n" . $post->normalizedLinkedUrl);
        }
        $title = self::normalizeText($post->title);
        $bodyText = HtmlDocumentParser::parseFragment((string) $post->bodyHtml)->body?->textContent;
        $body = self::normalizeText((string) $bodyText);
        if ($title === '' && $body === '') {
            return null;
        }

        return hash('sha256', "text\n" . $author . "\n" . $title . "\n" . $body);
    }

    private static function normalizeText(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text)));
    }

    private function __construct()
    {
    }
}
