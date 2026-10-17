<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Fetch\Support\HostKey;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Bluesky\Support\BlueskyEmbedMedia;
use App\Service\Bluesky\Support\QuotedViewRecord;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Text\Support\HtmlEscape;
use App\Service\Url\Support\AbsoluteHttpUrl;
use App\Service\Url\Support\HttpsImageUrl;

final readonly class PostEmbedRenderer
{
    private const string IMAGES = 'app.bsky.embed.images#view';
    private const string VIDEO = 'app.bsky.embed.video#view';
    private const string EXTERNAL = 'app.bsky.embed.external#view';
    private const string RECORD = 'app.bsky.embed.record#view';
    private const string RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';
    private const string FALLBACK_LABEL = 'View embedded content on Bluesky';

    /** Null when the post embeds nothing, or its URI is no post's. */
    public function render(JsonNodeModel $post): ?RenderedEmbedModel
    {
        $embed = $post->node('embed');
        $postUrl = AtPostUri::webUrl($post->string('uri') ?? '');
        if ($embed->type() === null || $postUrl === null) {
            return null;
        }

        return match ($embed->type()) {
            self::RECORD => $this->quote($embed->node('record')) ?? $this->fallback($postUrl),
            self::RECORD_WITH_MEDIA => (
                $this->quote($embed->node('record')->node('record')) ?? $this->fallback($postUrl)
            )->followedBy($this->media($embed->node('media'), $postUrl)),
            default => $this->media($embed, $postUrl),
        };
    }

    private function media(JsonNodeModel $embed, string $postUrl): RenderedEmbedModel
    {
        $rendered = match ($embed->type()) {
            self::IMAGES => $this->images($embed),
            self::VIDEO => $this->video($embed),
            self::EXTERNAL => $this->external($embed->node('external')),
            default => null,
        };

        return $rendered ?? $this->fallback($postUrl);
    }

    private function images(JsonNodeModel $embed): ?RenderedEmbedModel
    {
        $media = [];
        foreach ($embed->nodes('images') as $image) {
            $medium = BlueskyEmbedMedia::image($image);
            if ($medium === null) {
                continue;
            }
            $media[] = $medium;
        }

        if ($media === []) {
            return null;
        }
        $lead = new DeclaredImageModel($media[0]->url, $media[0]->width, $media[0]->height);

        return RenderedEmbedModel::media($lead, $media);
    }

    private function external(JsonNodeModel $external): ?RenderedEmbedModel
    {
        $gif = BlueskyEmbedMedia::gif($external);
        if ($gif === null) {
            return $this->linkCard($external);
        }
        $still = $gif->previewImageUrl;

        return RenderedEmbedModel::media(
            $still === null ? null : new DeclaredImageModel($still, $gif->width, $gif->height),
            [$gif],
        );
    }

    private function video(JsonNodeModel $embed): ?RenderedEmbedModel
    {
        $medium = BlueskyEmbedMedia::video($embed);
        if ($medium === null) {
            return null;
        }
        $thumbnail = $medium->previewImageUrl;

        return RenderedEmbedModel::media(
            $thumbnail === null ? null : new DeclaredImageModel($thumbnail, $medium->width, $medium->height),
            [$medium],
        );
    }

    private function linkCard(JsonNodeModel $external): ?RenderedEmbedModel
    {
        $url = AbsoluteHttpUrl::orNull($external->string('uri'));
        if ($url === null) {
            return null;
        }
        $title = $external->string('title');
        if ($title === null) {
            return RenderedEmbedModel::uncarded($url);
        }
        $thumbnail = HttpsImageUrl::orNull($external->string('thumb'));
        $card = StorableLinkCard::of(
            new LinkCardContent($url, $title, $external->string('description'), $thumbnail, HostKey::forUrl($url)),
        );

        return $card === null
            ? null
            : RenderedEmbedModel::card($card, $thumbnail === null ? null : new DeclaredImageModel($thumbnail));
    }

    private function quote(JsonNodeModel $record): ?RenderedEmbedModel
    {
        $quote = QuotedViewRecord::of($record);

        return $quote === null ? null : RenderedEmbedModel::quoted($quote);
    }

    private function fallback(string $postUrl): RenderedEmbedModel
    {
        return new RenderedEmbedModel(self::linkParagraph($postUrl, self::FALLBACK_LABEL));
    }

    private static function linkParagraph(string $url, string $label): string
    {
        return sprintf('<p><a href="%s">%s</a></p>', HtmlEscape::text($url), $label);
    }
}
