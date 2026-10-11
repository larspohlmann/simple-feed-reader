<?php

declare(strict_types=1);

namespace App\Service\Bluesky\EntryEmbedWriter;

use App\Entity\Entry;
use App\Repository\LinkCardRepository;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\Support\TrailingUrl;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\LinkCard\Support\SharedLinks;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Text\Support\HtmlEscape;

final readonly class EntryEmbedWriter implements EntryEmbedWriterInterface
{
    public function __construct(
        private PostEmbedRenderer $renderer,
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
        private LinkCardRepository $linkCards,
    ) {
    }

    public function fill(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        $embed = $this->renderer->render($post);
        if ($embed === null) {
            return FillOutcome::Unfilled;
        }

        $body = $entry->getContentHtml() ?? '';
        foreach ($embed->linkCards as $card) {
            $body = TrailingUrl::removedFrom($body, $card->url);
        }
        if ($embed->uncardedLink !== null) {
            $body = self::sharing($body, $embed->uncardedLink);
        }
        $this->linkCards->add($entry, $embed->linkCards);
        $entry->setContentHtml($this->sanitizer->sanitize($body . $embed->html));
        $entry->setSummary(EntrySnippet::from($body));
        if ($embed->leadImage !== null && $entry->getImageUrl() === null) {
            $this->imageWriter->write($entry, $embed->leadImage);
        }
        $assembled = EntryMediaAssembler::assemble(self::storedImage($entry), $embed->media, []);
        $entry->setMedia($assembled->media, $entry->getAttachments());

        return $embed->uncardedLink === null ? FillOutcome::Filled : FillOutcome::AwaitingLinkCards;
    }

    /** The body as the link-card pass reads it: the link it must card shared as a link. */
    private static function sharing(string $body, string $url): string
    {
        $shared = SharedLinks::of($body);
        if (\in_array($url, $shared->urls, true)) {
            return $shared->linkedHtml;
        }
        $escaped = HtmlEscape::text($url);

        return $shared->linkedHtml . sprintf('<p><a href="%s">%s</a></p>', $escaped, $escaped);
    }

    private static function storedImage(Entry $entry): ?DeclaredImageModel
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImageModel($url, $entry->getImageWidth(), $entry->getImageHeight());
    }
}
