<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

/**
 * The media a feed item declared, split into the two lists the entry stores:
 * visual media to show and enclosures to play or download.
 */
final readonly class ParsedMediaBundleModel
{
    /**
     * @param list<ParsedMediumModel>     $media
     * @param list<ParsedAttachmentModel> $attachments
     */
    public function __construct(
        public array $media = [],
        public array $attachments = [],
    ) {
    }
}
