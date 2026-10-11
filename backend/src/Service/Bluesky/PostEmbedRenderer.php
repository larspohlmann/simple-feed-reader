<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\LinkCardContent;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Fetch\Support\HostKey;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\LinkCard\Support\StorableLinkCard;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
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
    private const string VIEW_RECORD = 'app.bsky.embed.record#viewRecord';
    private const string POST_RECORD = 'app.bsky.feed.post';
    private const string WATCH_LABEL = 'Watch on Bluesky';
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
            self::VIDEO => $this->video($embed, $postUrl),
            self::EXTERNAL => $this->linkCard($embed->node('external')),
            default => null,
        };

        return $rendered ?? $this->fallback($postUrl);
    }

    private function images(JsonNodeModel $embed): ?RenderedEmbedModel
    {
        $tags = '';
        $media = [];
        foreach ($embed->nodes('images') as $image) {
            $url = HttpsImageUrl::orNull($image->string('fullsize'));
            if ($url === null) {
                continue;
            }
            $ratio = $image->node('aspectRatio');
            $tags .= sprintf(
                '<img src="%s" alt="%s"%s>',
                HtmlEscape::text($url),
                HtmlEscape::text($image->string('alt') ?? ''),
                self::dimensions($ratio),
            );
            $media[] = new ParsedMediumModel($url, VisualMediaKind::Image, $ratio->int('width'), $ratio->int('height'));
        }
        if ($media === []) {
            return null;
        }
        $lead = new DeclaredImageModel($media[0]->url, $media[0]->width, $media[0]->height);

        return new RenderedEmbedModel('<figure class="post-images">' . $tags . '</figure>', $lead, $media);
    }

    private function video(JsonNodeModel $embed, string $postUrl): ?RenderedEmbedModel
    {
        $playlist = HttpsImageUrl::orNull($embed->string('playlist'));
        if ($playlist === null) {
            return null;
        }
        $thumbnail = HttpsImageUrl::orNull($embed->string('thumbnail'));
        $ratio = $embed->node('aspectRatio');
        $width = $ratio->int('width');
        $height = $ratio->int('height');
        $poster = $thumbnail === null ? '' : sprintf(' poster="%s"', HtmlEscape::text($thumbnail));

        return new RenderedEmbedModel(
            sprintf(
                '<figure class="post-video"><video controls preload="none"%s src="%s"></video></figure>',
                $poster,
                HtmlEscape::text($playlist),
            ) . self::linkParagraph($postUrl, self::WATCH_LABEL),
            $thumbnail === null ? null : new DeclaredImageModel($thumbnail, $width, $height),
            [new ParsedMediumModel($playlist, VisualMediaKind::Video, $width, $height, $thumbnail)],
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
        $quotedUrl = AtPostUri::webUrl($record->string('uri') ?? '');
        $value = $record->node('value');
        if ($record->type() !== self::VIEW_RECORD || $value->type() !== self::POST_RECORD || $quotedUrl === null) {
            return null;
        }
        $card = StorableLinkCard::of(new LinkCardContent(
            $quotedUrl,
            self::authorName($record->node('author')),
            $value->string('text'),
            self::quotedImage($record->first('embeds')),
            HostKey::forUrl($quotedUrl),
        ));

        return $card === null ? null : RenderedEmbedModel::card($card, null);
    }

    private static function quotedImage(JsonNodeModel $embed): ?string
    {
        return match ($embed->type()) {
            self::IMAGES => $embed->first('images')->string('thumb'),
            self::VIDEO => $embed->string('thumbnail'),
            self::EXTERNAL => $embed->node('external')->string('thumb'),
            self::RECORD_WITH_MEDIA => self::quotedImage($embed->node('media')),
            default => null,
        };
    }

    private function fallback(string $postUrl): RenderedEmbedModel
    {
        return new RenderedEmbedModel(self::linkParagraph($postUrl, self::FALLBACK_LABEL));
    }

    private static function authorName(JsonNodeModel $author): string
    {
        $handle = $author->string('handle');
        $displayName = $author->string('displayName');
        if ($handle === null) {
            return $displayName ?? 'Bluesky';
        }

        return $displayName === null ? '@' . $handle : sprintf('%s (@%s)', $displayName, $handle);
    }

    private static function dimensions(JsonNodeModel $ratio): string
    {
        $width = $ratio->int('width');
        $height = $ratio->int('height');

        return $width === null || $height === null ? '' : sprintf(' width="%d" height="%d"', $width, $height);
    }

    private static function linkParagraph(string $url, string $label): string
    {
        return sprintf('<p><a href="%s">%s</a></p>', HtmlEscape::text($url), $label);
    }
}
