<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use App\Service\Url\Support\MixcloudUrl;

/**
 * The Mixcloud widget. A show is its key, `/<user>/<show>/`; the URL is rebuilt from the key alone, so `autoplay`
 * and every chrome flag cannot survive.
 */
final readonly class MixcloudEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'player-widget.mixcloud.com';
    private const string PLAYER_URL = 'https://' . self::HOST . '/widget/iframe/?feed=';
    /** A key segment after rawurlencode(): a `%` of the API's own encoding arrives as `%25`. */
    private const string ENCODED_SEGMENT = '(?:[A-Za-z0-9_-]|%25[0-9A-Fa-f]{2})+';
    private const string LABEL = 'Listen on Mixcloud';

    public function target(string $url): ?EmbedTargetModel
    {
        $key = MixcloudUrl::showKey($url) ?? $this->widgetKey($url);

        return $key === null ? null : new EmbedTargetModel(self::PLAYER_URL . rawurlencode($key), null, self::LABEL);
    }

    public function frames(): array
    {
        return [new EmbedFrameModel(
            '^https://' . preg_quote(self::HOST, '#') . '/widget/iframe/\?feed=%2F'
                . self::ENCODED_SEGMENT . '%2F' . self::ENCODED_SEGMENT . '%2F$',
            EmbedKind::Audio,
            EmbedShape::Strip,
        )];
    }

    public function sourceHosts(): array
    {
        return [self::HOST];
    }

    /** The widget's `feed` is a key, or a whole show URL in the iframe Mixcloud's oEmbed hands out. */
    private function widgetKey(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || strtolower($parts['host'] ?? '') !== self::HOST) {
            return null;
        }
        if (!\in_array(rtrim($parts['path'] ?? '', '/'), ['', '/widget/iframe'], true)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $queryParameters);
        $feed = $queryParameters['feed'] ?? null;
        if (!\is_string($feed)) {
            return null;
        }

        return MixcloudUrl::isShowKey($feed) ? $feed : MixcloudUrl::showKey($feed);
    }
}
