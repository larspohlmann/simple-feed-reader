<?php

declare(strict_types=1);

namespace App\Service\Social\BlueskyPost\EntryEmbedWriter;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\LinkCardContent;
use App\Enum\ShareKind;
use App\Service\Content\LinkCard\EntryLinks;
use App\Service\Content\LinkCard\Support\TrailingUrl;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Sanitize\EntrySanitizer;
use App\Service\Foundation\Text\Support\HtmlEscape;
use App\Service\Foundation\Url\Support\TrackingFreeUrl;
use App\Service\Ingestion\Ingest\EntryImageWriter;
use App\Service\Ingestion\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingestion\Ingest\Support\EntrySnippet;
use App\Service\Outbound\Image\Model\DeclaredImageModel;
use App\Service\Social\BlueskyPost\Model\FillOutcome;
use App\Service\Social\BlueskyPost\Model\RenderedEmbedModel;
use App\Service\Social\BlueskyPost\PostEmbedRenderer;
use App\Service\Social\BlueskyPost\Support\AtPostUri;
use App\Service\Social\BlueskyPost\Support\BlueskyRichText;
use App\Service\Social\Share\Model\SharedLinksModel;
use App\Service\Social\Share\Support\SharedLinks;

final readonly class EntryEmbedWriter implements EntryEmbedWriterInterface
{
    public function __construct(
        private PostEmbedRenderer $renderer,
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
        private EntryLinks $entryLinks,
    ) {
    }

    public function fill(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        $embed = $this->renderer->render($post);
        if ($embed === null) {
            return $this->fillFromText($entry, $post);
        }

        $body = self::sharedBody($entry->getText()->getContentHtml() ?? '', $embed);
        $this->entryLinks->attach($entry, $embed->linkCards);
        $entry->recordText(
            $entry->getText()->withContentHtml($this->sanitizer->sanitize($body->linkedHtml . $embed->html)),
        );
        $entry->recordText($entry->getText()->withSummary(EntrySnippet::from($body->linkedHtml)));
        if ($embed->leadImage !== null && $entry->getImageUrl() === null) {
            $this->imageWriter->write($entry, $embed->leadImage);
        }
        $assembled = EntryMediaAssembler::assemble(self::findLeadMedium($entry, $embed), $embed->media, []);
        $entry->setMedia($assembled->media, $entry->getAttachments());

        $cardedUrls = array_map(static fn (LinkCardContent $card): string => $card->url, $embed->linkCards);

        $outcome = $body->uncardedBy($cardedUrls) === [] ? FillOutcome::Filled : FillOutcome::AwaitingLinkCards;
        if ($embed->quote === null) {
            $entry->setShareKind(self::classifyShareKind($embed, $outcome, $assembled->media));
        } else {
            $this->entryLinks->quote($entry, $embed->quote);
        }

        return $outcome;
    }

    private function fillFromText(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        $shared = SharedLinks::fromBodyHtml(self::buildTextBody($entry, $post));
        $pages = array_filter($shared->urls, static fn (string $url): bool => !AtPostUri::isWebUrl($url));
        if ($pages === []) {
            return FillOutcome::Unfilled;
        }

        $entry->recordText($entry->getText()->withContentHtml($this->sanitizer->sanitize($shared->linkedHtml)));
        $entry->recordText($entry->getText()->withSummary(EntrySnippet::from($shared->linkedHtml)));
        $entry->setShareKind(ShareKind::Link);

        return FillOutcome::AwaitingLinkCards;
    }

    /** An RSS body shows a link shortened; the post's own facets know where it goes. */
    private static function buildTextBody(Entry $entry, JsonNodeModel $post): string
    {
        $body = $entry->getText()->getContentHtml() ?? '';
        $record = $post->node('record');
        if (!BlueskyRichText::linksAWebPage($record->nodes('facets'))) {
            return $body;
        }

        return BlueskyRichText::asHtml($record->text('text') ?? '', $record->nodes('facets')) ?? $body;
    }

    /** @param list<EntryMedium> $media */
    private static function classifyShareKind(RenderedEmbedModel $embed, FillOutcome $outcome, array $media): ?ShareKind
    {
        if ($embed->linkCards !== [] || $outcome === FillOutcome::AwaitingLinkCards) {
            return ShareKind::Link;
        }

        return $media === [] ? null : ShareKind::Media;
    }

    /** The body without the URLs its cards replace, every other link it shares linked, the uncarded one included. */
    private static function sharedBody(string $html, RenderedEmbedModel $embed): SharedLinksModel
    {
        foreach ($embed->linkCards as $card) {
            $html = TrailingUrl::stripTrailingUrl($html, $card->url);
        }
        $shared = SharedLinks::fromBodyHtml($html);
        $uncarded = $embed->uncardedLink;
        if ($uncarded === null) {
            return $shared;
        }
        $cleaned = TrackingFreeUrl::stripTracking($uncarded);
        if (\in_array($cleaned, $shared->urls, true)) {
            return $shared;
        }
        $escaped = HtmlEscape::text($uncarded);

        return new SharedLinksModel(
            [...$shared->urls, $cleaned],
            $shared->linkedHtml . sprintf('<p><a href="%s">%s</a></p>', $escaped, $escaped),
            $shared->ownProse,
        );
    }

    /** A card's thumbnail leads the entry but belongs to the card, never to the post's own media. */
    private static function findLeadMedium(Entry $entry, RenderedEmbedModel $embed): ?DeclaredImageModel
    {
        return $embed->linkCards === [] ? self::storedImage($entry) : null;
    }

    private static function storedImage(Entry $entry): ?DeclaredImageModel
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImageModel($url, $entry->getImageWidth(), $entry->getImageHeight());
    }
}
