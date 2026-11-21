<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\Support;

use App\Service\Foundation\Html\Support\HtmlDocumentParser;
use App\Service\Ingestion\Ingest\Model\CrossPostModel;
use Dom\Element;
use Dom\Node;
use Dom\Text;

final class CrossPostHash
{
    private const array BLOCK_ELEMENTS = [
        'p', 'div', 'br', 'hr', 'li', 'ul', 'ol', 'blockquote', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'tr', 'td', 'th',
    ];

    public static function build(CrossPostModel $post): ?string
    {
        if ($post->authorUrl === null || $post->authorUrl === '') {
            return null;
        }
        $author = rtrim(mb_strtolower($post->authorUrl), '/');
        if ($post->normalizedLinkedUrl !== null && $post->normalizedLinkedUrl !== '') {
            return hash('sha256', "link\n" . $author . "\n" . $post->normalizedLinkedUrl);
        }
        $title = self::normalizeText($post->title);
        $body = self::normalizeText(self::readText(HtmlDocumentParser::parseFragment((string) $post->bodyHtml)->body));
        if ($title === '' && $body === '') {
            return null;
        }

        return hash('sha256', "text\n" . $author . "\n" . $title . "\n" . $body);
    }

    private static function readText(?Node $node): string
    {
        if ($node === null) {
            return '';
        }
        if ($node instanceof Text) {
            return $node->data;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::readText($child);
        }

        return $node instanceof Element && in_array($node->localName, self::BLOCK_ELEMENTS, true)
            ? ' ' . $text . ' '
            : $text;
    }

    private static function normalizeText(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text)));
    }

    private function __construct()
    {
    }
}
