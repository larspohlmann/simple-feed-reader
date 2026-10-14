<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\EntryMedium;
use App\Entity\QuotedPostContent;
use App\Entity\QuotedPostReference;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\LinkCard\Support\QuoteLine;
use App\Service\LinkCard\Support\StorableQuotedPost;
use App\Service\Parser\Support\DateParser;
use App\Service\Text\Support\PlainText;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** A status another one quotes, as its quote card shows it. */
final class MastodonQuotedStatus
{
    private const string ACCEPTED = 'accepted';

    /** A status's `quote`: only an accepted one with the whole status counts; a shallow one names an id alone. */
    public static function ofQuote(JsonNodeModel $quote): ?QuotedPostContent
    {
        return $quote->string('state') === self::ACCEPTED ? self::of($quote->node('quoted_status')) : null;
    }

    public static function of(JsonNodeModel $status): ?QuotedPostContent
    {
        $url = AbsoluteHttpUrl::orNull($status->string('url')) ?? AbsoluteHttpUrl::orNull($status->string('uri'));
        $account = $status->node('account');
        $handle = self::handleOf($account);
        if ($url === null || $handle === null) {
            return null;
        }
        $content = $status->string('content') ?? '';
        $quoted = QuoteLine::targetIn($content);
        $body = $quoted === null ? $content : QuoteLine::removedFrom($content, $quoted);

        return StorableQuotedPost::of(new QuotedPostContent(
            $url,
            $account->string('display_name') ?? '',
            $handle,
            $account->string('avatar'),
            DateParser::parse($status->string('created_at')),
            self::textOf($body, $status->string('spoiler_text')),
            self::mediaOf($status),
            self::nestedOf($status->node('quote'), $quoted),
        ));
    }

    /** A local account's acct is the bare user name: the profile's host completes it. */
    private static function handleOf(JsonNodeModel $account): ?string
    {
        $acct = $account->string('acct');
        $host = parse_url((string) $account->string('url'), \PHP_URL_HOST);
        if ($acct === null || str_contains($acct, '@') || !\is_string($host)) {
            return $acct;
        }

        return $acct . '@' . $host;
    }

    /** @return list<EntryMedium> */
    private static function mediaOf(JsonNodeModel $status): array
    {
        $attachments = MastodonAttachments::of($status->nodes('media_attachments'));

        return EntryMediaAssembler::assemble(null, $attachments, [])->media;
    }

    private static function textOf(string $html, ?string $warning): string
    {
        $lines = PlainText::linesFromHtmlBlocks($html);

        return implode("\n", $warning === null ? $lines : ['CW: ' . $warning, ...$lines]);
    }

    private static function nestedOf(JsonNodeModel $quote, ?string $reTarget): ?QuotedPostReference
    {
        $full = self::ofQuote($quote);
        if ($full !== null) {
            return new QuotedPostReference($full->url, $full->authorHandle);
        }

        return $reTarget === null ? null : new QuotedPostReference($reTarget, null);
    }

    private function __construct()
    {
    }
}
