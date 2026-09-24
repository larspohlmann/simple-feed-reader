<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Discussion\Discussion;

/**
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier;
 * downstream tasks reconstruct it field-for-field (docs/superpowers/plans/2026-09-24-1140-entry-comments.md).
 */
final readonly class ParsedEntry
{
    public Discussion $discussion;

    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
        public ParsedEntryMedia $media = new ParsedEntryMedia(),
        /** @var list<ParsedCategory> */
        public array $categories = [],
        ?Discussion $discussion = null,
        public ?string $authorUrl = null,
    ) {
        $this->discussion = $discussion ?? Discussion::none();
    }
}
