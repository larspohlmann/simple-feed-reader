<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\TrailingUrl;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\Sanitize\EntrySanitizer;

final readonly class EntryEmbedWriter
{
    public function __construct(
        private PostEmbedRenderer $renderer,
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
    ) {
    }

    public function fill(Entry $entry, JsonNodeModel $post): bool
    {
        $embed = $this->renderer->render($post);
        if ($embed === null) {
            return false;
        }

        $body = $entry->getContentHtml() ?? '';
        if ($embed->linkCardUrl !== null) {
            $body = TrailingUrl::removedFrom($body, $embed->linkCardUrl);
        }
        $entry->setContentHtml($this->sanitizer->sanitize($body . $embed->html));
        $entry->setSummary(EntrySnippet::from($body));
        if ($embed->leadImage !== null && $entry->getImageUrl() === null) {
            $this->imageWriter->write($entry, $embed->leadImage);
        }
        $assembled = EntryMediaAssembler::assemble(self::storedImage($entry), $embed->media, []);
        $entry->setMedia($assembled->media, $entry->getAttachments());

        return true;
    }

    private static function storedImage(Entry $entry): ?DeclaredImageModel
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImageModel($url, $entry->getImageWidth(), $entry->getImageHeight());
    }
}
